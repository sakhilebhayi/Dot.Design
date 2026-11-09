<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Models\DesignAiProposal;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\Assistant\Contracts\ModelClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantLimitReached;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\BriefValidator;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\DocumentSchema;
use App\Services\Design\Formats;
use App\Services\Design\ProjectMeta;

/**
 * Improve an existing page. The result is always a PROPOSAL the person reviews: validated patch operations, or (for
 * "make it suitable for Instagram") a resize suggestion. Nothing here touches the design.
 */
class ImproveService
{
    public function __construct(
        private readonly ModelClient $model,
        private readonly Catalogue $catalogue,
        private readonly OpValidator $validator,
        private readonly ResizeSuggester $resize,
        private readonly DocumentSanitiser $sanitiser,
        private readonly BriefValidator $briefs,
        private readonly UsageGuard $usage,
        private readonly Formats $formats,
        private readonly ProjectMeta $meta,
    ) {}

    /**
     * @param  list<string>  $selection
     * @return array{status: string, proposal?: DesignAiProposal, message?: string}
     *
     * @throws AssistantUnavailable
     * @throws AssistantLimitReached
     * @throws AssistantFailed
     */
    public function propose(User $user, DesignProject $project, DesignCanvas $canvas, string $instruction, array $selection = []): array
    {
        $instruction = $this->briefs->clean($instruction);
        $page = $this->pageArray($project, $canvas);

        $resize = $this->resize->suggest($instruction, $page['width'], $page['height']);
        if ($resize !== null) {
            return $this->resizeProposal($user, $project, $canvas, $instruction, $page, $resize);
        }

        if (! $this->model->available()) {
            throw new AssistantUnavailable;
        }

        $teamId = $project->team_id;
        $this->usage->check($user, $teamId);

        $kit = $this->kitFor($project);
        $message = $this->message($instruction, $page, $selection, $kit);

        try {
            $call = $this->model->callTool(Prompts::improveSystem(), $message, Prompts::improveTool(), 'improve');
        } catch (AssistantFailed $failure) {
            $this->usage->recordFailure($user, $teamId, $project->id, 'improve', $instruction, $failure);

            throw $failure;
        }

        $source = $instruction."\n".$this->pageText($page['elements']);
        $result = $this->validator->validate($page, ModelOps::fromToolInput($call->input), $kit, $selection, $source);

        if ($result->isEmpty()) {
            DesignAiProposal::create([
                'design_project_id' => $project->id,
                'team_id' => $teamId,
                'user_id' => $user->id,
                'mode' => 'improve',
                'status' => 'failed',
                'prompt' => mb_substr($instruction, 0, 2000),
                'payload' => ['page_id' => $canvas->id, 'dropped' => $result->dropped],
                'model' => $call->model ?? (string) config('design_ai.model'),
                'tokens_in' => $call->tokensIn,
                'tokens_out' => $call->tokensOut,
                'error' => 'no_valid_changes',
            ]);

            return ['status' => 'nothing', 'message' => 'The assistant did not find a safe change for that. Try asking in a different way, or select the part you want changed.'];
        }

        $summary = TextGuard::clean($call->input['summary'] ?? null, 240) ?? 'Changes to make this page better.';

        $proposal = DesignAiProposal::create([
            'design_project_id' => $project->id,
            'team_id' => $teamId,
            'user_id' => $user->id,
            'mode' => 'improve',
            'status' => 'proposed',
            'prompt' => mb_substr($instruction, 0, 2000),
            'payload' => $this->patchPayload($canvas, $result, 'ai', $summary),
            'model' => $call->model ?? (string) config('design_ai.model'),
            'tokens_in' => $call->tokensIn,
            'tokens_out' => $call->tokensOut,
        ]);

        return ['status' => 'ok', 'proposal' => $proposal];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array{format_key: string, platform: string}  $resize
     * @return array{status: string, proposal?: DesignAiProposal, message?: string}
     */
    private function resizeProposal(User $user, DesignProject $project, DesignCanvas $canvas, string $instruction, array $page, array $resize): array
    {
        $key = $resize['format_key'];

        if ($this->formats->matchingKey((int) $page['width'], (int) $page['height']) === $key) {
            return ['status' => 'nothing', 'message' => 'This page is already the right size for that. Nothing to change.'];
        }

        $format = $this->formats->get($key);
        $proposal = DesignAiProposal::create([
            'design_project_id' => $project->id,
            'team_id' => $project->team_id,
            'user_id' => $user->id,
            'mode' => 'improve',
            'status' => 'proposed',
            'prompt' => mb_substr($instruction, 0, 2000),
            'payload' => [
                'type' => 'suggest_resize',
                'source' => 'automatic',
                'page_id' => $canvas->id,
                'format_key' => $key,
                'summary' => 'Resize this design to '.$format['label'].'.',
            ],
        ]);

        return ['status' => 'ok', 'proposal' => $proposal];
    }

    /** @return array<string, mixed> */
    public function patchPayload(DesignCanvas $canvas, OpResult $result, string $source, string $summary): array
    {
        return [
            'type' => 'patch',
            'source' => $source,
            'page_id' => $canvas->id,
            'base_revision' => (int) $canvas->revision,
            'summary' => $summary,
            'ops' => $result->ops,
            'changes' => $result->changes,
            'notes' => $result->notes,
            'dropped' => $result->dropped,
        ];
    }

    /** @return array{width: float, height: float, background: ?array, elements: list<array<string, mixed>>} */
    public function pageArray(DesignProject $project, DesignCanvas $canvas): array
    {
        return [
            'width' => (float) ($canvas->width ?? $project->width),
            'height' => (float) ($canvas->height ?? $project->height),
            'background' => $canvas->background ?: ['type' => 'solid', 'color' => $canvas->background_color ?: '#ffffff'],
            'elements' => $this->sanitiser->readElements($canvas->elements ?? [])['elements'],
        ];
    }

    public function kitFor(DesignProject $project): ?BrandKit
    {
        $link = $this->meta->brandLink($project);

        return $this->catalogue->kit($link['kit_id']);
    }

    /** @param list<array<string, mixed>> $elements */
    public function pageText(array $elements): string
    {
        $out = [];
        foreach ($elements as $el) {
            if (($el['type'] ?? null) === 'text') {
                $out[] = (string) ($el['text'] ?? '');
            }
            if (($el['type'] ?? null) === 'group') {
                $out[] = $this->pageText((array) ($el['children'] ?? []));
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<string>  $selection
     */
    private function message(string $instruction, array $page, array $selection, ?BrandKit $kit): string
    {
        $limit = (int) config('design_ai.input.max_elements_sent', 80);
        $count = 0;
        $digest = $this->digest($page['elements'], $count, $limit);
        $background = is_array($page['background']) ? ($page['background']['color'] ?? null) : null;

        $parts = [];
        $brand = $this->catalogue->brandSummary($kit);
        if ($brand !== null) {
            $parts[] = "<brand_kit>\n".json_encode($brand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</brand_kit>";
        }

        $parts[] = 'allowed_fonts: '.implode(', ', DocumentSchema::shared()->fontKeys());
        $parts[] = "<page>\nsize: {$page['width']} x {$page['height']}\nbackground: ".(is_string($background) ? $background : 'unknown')."\nelements (top to bottom is back to front):\n".json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</page>";

        if ($selection !== []) {
            $parts[] = 'The person selected these element ids; change only them: '.implode(', ', array_map(fn ($id) => TextGuard::data((string) $id, 64), $selection));
        }

        $parts[] = "<user_request>\n".TextGuard::data($instruction, (int) config('design_ai.input.instruction_max') + 50)."\n</user_request>";

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    private function digest(array $elements, int &$count, int $limit): array
    {
        $out = [];
        $max = (int) config('design_ai.input.element_text_max', 240);

        foreach ($elements as $el) {
            if ($count >= $limit) {
                break;
            }
            $count++;

            $row = array_filter([
                'id' => $el['id'],
                'type' => $el['type'],
                'role' => ($el['role'] ?? 'other') === 'other' ? null : $el['role'],
                'name' => isset($el['name']) ? TextGuard::data((string) $el['name'], 60) : null,
                'x' => round((float) $el['x']), 'y' => round((float) $el['y']), 'w' => round((float) $el['width']), 'h' => round((float) $el['height']),
                'rotation' => ! empty($el['rotation']) ? $el['rotation'] : null,
                'opacity' => isset($el['opacity']) && $el['opacity'] < 1 ? $el['opacity'] : null,
                'locked' => ! empty($el['locked']) ? true : null,
            ], fn ($v) => $v !== null);

            if ($el['type'] === 'text') {
                $row += array_filter([
                    'text' => TextGuard::data((string) ($el['text'] ?? ''), $max),
                    'font' => $el['font'] ?? null, 'fontSize' => $el['fontSize'] ?? null, 'fontWeight' => $el['fontWeight'] ?? null,
                    'color' => $el['color'] ?? null, 'align' => $el['align'] ?? null, 'lineHeight' => $el['lineHeight'] ?? null,
                ], fn ($v) => $v !== null);
            } elseif ($el['type'] === 'shape') {
                $row += array_filter(['kind' => $el['kind'] ?? null, 'fill' => is_string($el['fill'] ?? null) ? $el['fill'] : 'gradient'], fn ($v) => $v !== null);
            } elseif ($el['type'] === 'image') {
                $row['has_picture'] = ! empty($el['asset_id']);
            } elseif ($el['type'] === 'icon') {
                $row += ['icon' => $el['icon'] ?? null, 'color' => $el['color'] ?? null];
            } elseif ($el['type'] === 'group') {
                $row['children'] = $this->digest((array) ($el['children'] ?? []), $count, $limit);
            }

            $out[] = $row;
        }

        return $out;
    }
}
