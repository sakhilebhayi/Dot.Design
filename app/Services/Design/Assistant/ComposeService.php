<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Models\DesignAiProposal;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\ActivityLog;
use App\Services\Design\Assistant\Contracts\ModelClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantLimitReached;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\BriefValidator;
use App\Services\Design\DocumentContext;
use App\Services\Design\DocumentRejected;
use App\Services\Design\DocumentSanitiser;
use App\Services\Design\ProjectMeta;
use App\Services\Design\StarterProjectBuilder;
use App\Services\Design\TemplateInstantiator;
use App\Support\Design\DesignAccess;

/**
 * Idea to editable design.
 *
 * The model returns a PLAN (a catalogue template, a format, a style, a palette choice and short slot copy). Code
 * validates it, substitutes everything the person typed (their words always win over model text), builds the pages
 * with the same TemplateInstantiator the guided brief uses, runs the document sanitiser over them, and creates an
 * ordinary private draft project. No layout JSON from the model is ever used.
 */
class ComposeService
{
    public function __construct(
        private readonly ModelClient $model,
        private readonly Catalogue $catalogue,
        private readonly PlanValidator $plans,
        private readonly LiteralExtractor $literals,
        private readonly BriefValidator $briefs,
        private readonly TemplateInstantiator $instantiator,
        private readonly StarterProjectBuilder $builder,
        private readonly DocumentSanitiser $sanitiser,
        private readonly ProjectMeta $meta,
        private readonly UsageGuard $usage,
        private readonly ActivityLog $activity,
    ) {}

    /**
     * @param  array<string, string>  $answers  answers to earlier questions, keyed by question id
     *
     * @throws AssistantUnavailable
     * @throws AssistantLimitReached
     * @throws AssistantFailed
     */
    public function compose(User $user, string $prompt, ?string $formatKey = null, array $answers = [], ?int $kitId = null): ComposeOutcome
    {
        $prompt = $this->briefs->clean($prompt, true);
        $answers = $this->cleanAnswers($answers);
        $answered = $answers !== [];
        $source = $prompt."\n".implode("\n", $answers);

        $teamId = DesignAccess::defaultTeamId($user->id);

        if (! $this->model->available()) {
            throw new AssistantUnavailable;
        }
        $this->usage->check($user, $teamId);

        $literals = $this->literalsFor($source, $answers);
        $kit = $this->catalogue->kit($kitId);

        $catalogue = $this->catalogue->build($user, $formatKey);
        $tool = Prompts::composeTool(
            array_column($catalogue['templates'], 'slug'),
            array_column($catalogue['formats'], 'key'),
            array_keys((array) config('design_brief_flow.styles')),
        );

        try {
            $call = $this->model->callTool(Prompts::composeSystem(), $this->message($prompt, $answers, $formatKey, $catalogue, $kit), $tool, 'compose');
        } catch (AssistantFailed $failure) {
            $this->usage->recordFailure($user, $teamId, null, 'compose', $prompt, $failure);

            throw $failure;
        }

        $plan = $this->plans->validate($call->input, $user, $source, $literals, $formatKey, $kit, $answered);
        $questions = $plan->questions;

        if (! $answered && $plan->businessName === null && ! in_array('business_name', array_column($questions, 'id'), true)) {
            array_unshift($questions, ['id' => 'business_name', 'text' => 'What name should go on your design? For example your business name.']);
        }

        if (! $answered && $questions !== []) {
            $questions = array_slice($questions, 0, 2);
            $this->record($user, $teamId, null, 'proposed', $prompt, ['questions' => array_column($questions, 'id')], $call);

            return ComposeOutcome::asking($questions);
        }

        $brief = $this->brief($plan, $literals, $answers, $kit, $user);
        $applyBrand = $plan->useBrand && $kit !== null;

        try {
            $this->assertDocumentsAcceptable($plan, $brief, $kit, $applyBrand, $user);
        } catch (AssistantFailed $failure) {
            $this->record($user, $teamId, null, 'failed', $prompt, null, $call, $failure->reason);

            throw $failure;
        }

        $built = $this->builder->create($user, $brief, $plan->template, $kit, $applyBrand, null);
        /** @var DesignProject $project */
        $project = $built['project'];

        $this->meta->write($project, ['brief' => $this->meta->brief($project) + ['ai_prompt' => mb_substr($prompt, 0, (int) config('design_ai.input.prompt_max'))]]);

        $result = $built['result'];
        $row = $this->record($user, $teamId, $project->id, 'applied', $prompt, [
            'template' => $plan->template?->slug,
            'format_key' => $built['format_key'],
            'style' => $plan->style,
            'brand_applied' => $applyBrand,
            'copy_slots' => array_keys($plan->copy),
            'literal_slots' => array_keys($literals),
        ], $call);

        $this->activity->record($user, $project, 'ai.compose', 'ai_proposal', $row->id, ['template' => $plan->template?->slug, 'format' => $built['format_key']]);

        return ComposeOutcome::created($project, [
            'template' => $plan->template === null ? null : ['slug' => $plan->template->slug, 'name' => $plan->template->name],
            'format_key' => $built['format_key'],
            'filled' => $result === null ? [] : array_values(array_map(fn (array $f) => ['slot' => $f['slot'], 'source' => $f['source']], $result->filled)),
            'left' => $result === null ? [] : array_values(array_map(fn (array $l) => ['slot' => $l['slot'], 'label' => config("design_copy.slots.{$l['slot']}.label", $l['slot']), 'reason' => $l['reason']], $result->left)),
            'warnings' => array_values(array_merge($plan->notes, $result?->warnings ?? [])),
            'brand_applied' => $applyBrand,
        ]);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    private function cleanAnswers(array $answers): array
    {
        $out = [];

        foreach ($answers as $id => $value) {
            if (! is_string($id) || ! in_array($id, Prompts::QUESTION_IDS, true) || ! is_string($value)) {
                continue;
            }

            $clean = $this->briefs->clean($value);
            if ($clean !== '' && mb_strlen($clean) <= 200) {
                $out[$id] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    private function literalsFor(string $source, array $answers): array
    {
        $found = $this->literals->extract($source);

        // A direct answer is the person's own word for exactly that slot.
        foreach (['business_name', 'date', 'venue', 'offer'] as $slot) {
            if (isset($answers[$slot])) {
                $found[$slot] = $answers[$slot];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $answers
     * @param  array{templates: list<array<string, mixed>>, formats: list<array<string, mixed>>}  $catalogue
     */
    private function message(string $prompt, array $answers, ?string $formatKey, array $catalogue, ?BrandKit $kit): string
    {
        $parts = [];

        $brand = $this->catalogue->brandSummary($kit);
        if ($brand !== null) {
            $parts[] = "<brand_kit>\n".json_encode($brand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</brand_kit>";
        }

        $templates = array_map(fn (array $t) => json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $catalogue['templates']);
        $formats = array_map(fn (array $f) => "{$f['key']}: {$f['label']} ({$f['size']})", $catalogue['formats']);
        $parts[] = "<catalogue>\nTemplates, one per line:\n".implode("\n", $templates)."\n\nFormats:\n".implode("\n", $formats)."\n</catalogue>";

        if ($formatKey !== null) {
            $parts[] = "The person chose this size, so use it: {$formatKey}";
        }

        $parts[] = "<user_request>\n".TextGuard::data($prompt, (int) config('design_ai.input.prompt_max') + 100)."\n</user_request>";

        if ($answers !== []) {
            $lines = [];
            foreach ($answers as $id => $value) {
                $lines[] = $id.': '.TextGuard::data($value, 200);
            }
            $parts[] = "<answers>\nThe person already answered these questions. Do not ask any more questions.\n".implode("\n", $lines)."\n</answers>";
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string, string>  $literals
     * @param  array<string, string>  $answers
     * @return array<string, mixed> a validated brief
     */
    private function brief(ValidPlan $plan, array $literals, array $answers, ?BrandKit $kit, User $user): array
    {
        $details = $plan->copy;
        foreach ($literals as $slot => $value) {
            if ($slot !== 'business_name') {
                $details[$slot] = $value;
            }
        }

        $input = array_filter([
            'business_name' => $plan->businessName,
            'details' => $details,
            'style' => $plan->style,
            'format_key' => $plan->formatKey,
            'brand_kit_id' => $kit?->id,
            'apply_brand' => $plan->useBrand && $kit !== null,
            'what' => $answers['what'] ?? null,
            'goal' => $this->goalFor($plan),
        ], fn ($v) => $v !== null && $v !== '');

        // Drop whatever the brief rules refuse (a malformed phone number, say) rather than fail the whole design.
        for ($i = 0; $i < 4; $i++) {
            $validation = $this->briefs->validate($input, $user, requireEssentials: false, requireGoal: false);
            if ($validation->passes()) {
                return $validation->brief;
            }

            foreach (array_keys($validation->errors) as $field) {
                if (str_starts_with($field, 'details.')) {
                    unset($input['details'][substr($field, 8)]);
                } else {
                    unset($input[$field]);
                }
            }
        }

        return $this->briefs->validate(['format_key' => $plan->formatKey], $user, requireEssentials: false, requireGoal: false)->brief;
    }

    private function goalFor(ValidPlan $plan): ?string
    {
        foreach ((array) ($plan->template?->purposes ?? []) as $purpose) {
            foreach ((array) config('design_brief_flow.goals') as $key => $goal) {
                if (($goal['purpose'] ?? null) === $purpose) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Instantiate once without saving and run every page through the document sanitiser, so a template that could
     * not be stored never creates a project.
     *
     * @param  array<string, mixed>  $brief
     */
    private function assertDocumentsAcceptable(ValidPlan $plan, array $brief, ?BrandKit $kit, bool $applyBrand, User $user): void
    {
        if ($plan->template === null) {
            return;
        }

        $result = $this->instantiator->instantiate($plan->template, $brief, $kit, $applyBrand, $user);
        $context = new DocumentContext(fn (array $ids) => $ids);

        try {
            foreach ($result->pages as $page) {
                $this->sanitiser->sanitisePage(['elements' => $page['elements'] ?? []], $context);
            }
        } catch (DocumentRejected) {
            throw AssistantFailed::unusable('document_rejected');
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function record(User $user, ?int $teamId, ?int $projectId, string $status, string $prompt, ?array $payload, ToolCall $call, ?string $error = null): DesignAiProposal
    {
        return DesignAiProposal::create([
            'design_project_id' => $projectId,
            'team_id' => $teamId,
            'user_id' => $user->id,
            'mode' => 'compose',
            'status' => $status,
            'prompt' => mb_substr($prompt, 0, 2000),
            'payload' => $payload,
            'model' => $call->model ?? (string) config('design_ai.model'),
            'tokens_in' => $call->tokensIn,
            'tokens_out' => $call->tokensOut,
            'error' => $error,
        ]);
    }
}
