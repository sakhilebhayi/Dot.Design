<?php

namespace App\Services\Design\Assistant;

use App\Models\BrandKit;
use App\Models\DesignAiProposal;
use App\Models\User;
use App\Services\Design\Assistant\Contracts\ModelClient;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantLimitReached;
use App\Services\Design\Assistant\Exceptions\AssistantUnavailable;
use App\Services\Design\BriefValidator;
use App\Support\Design\DesignAccess;

/**
 * Marketing copy suggestions. The model writes; code keeps only what is safe to print: nothing longer than the
 * kind allows, no number, price, phone number, web address or handle the person did not supply, and none of the
 * words the brand voice says to avoid.
 */
class CopyService
{
    public const KINDS = [
        'headline' => 80,
        'promo' => 240,
        'product_description' => 600,
        'cta' => 40,
        'caption' => 300,
        'campaign_concept' => 600,
    ];

    public const FIELDS = ['business_name', 'product', 'audience', 'offer', 'details', 'tone'];

    public function __construct(
        private readonly ModelClient $model,
        private readonly Catalogue $catalogue,
        private readonly BriefValidator $briefs,
        private readonly UsageGuard $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return array{status: string, suggestions?: list<array{id: int, text: string}>, dropped?: int, message?: string, proposal_id?: int}
     *
     * @throws AssistantUnavailable
     * @throws AssistantLimitReached
     * @throws AssistantFailed
     */
    public function suggest(User $user, string $kind, array $fields, int $count, ?int $kitId = null, ?int $projectId = null, ?int $teamId = null): array
    {
        $max = self::KINDS[$kind];
        $count = min(max($count, 1), 5);

        $clean = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $clean[$field] = mb_substr($this->briefs->clean($value, $field === 'details'), 0, (int) config('design_ai.input.copy_field_max', 400));
            }
        }

        if (! $this->model->available()) {
            throw new AssistantUnavailable;
        }

        $teamId ??= DesignAccess::defaultTeamId($user->id);
        $this->usage->check($user, $teamId);

        $kit = $this->catalogue->kit($kitId);
        $brand = $this->catalogue->brandSummary($kit);

        $lines = ["kind: {$kind} (each suggestion at most {$max} characters)", "count: {$count}"];
        foreach ($clean as $field => $value) {
            $lines[] = $field.': '.TextGuard::data($value, 400);
        }
        $message = "<brief>\n".implode("\n", $lines)."\n</brief>";
        if ($brand !== null) {
            $message = "<brand_kit>\n".json_encode($brand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</brand_kit>\n\n".$message;
        }

        try {
            $call = $this->model->callTool(Prompts::copySystem(), $message, Prompts::copyTool(), 'copy');
        } catch (AssistantFailed $failure) {
            $this->usage->recordFailure($user, $teamId, $projectId, 'copy', $clean['product'] ?? $clean['business_name'] ?? null, $failure);

            throw $failure;
        }

        [$kept, $dropped] = $this->filter((array) ($call->input['suggestions'] ?? []), $max, $count, implode("\n", $clean), $kit);

        $status = $kept === [] ? 'failed' : 'proposed';
        $row = DesignAiProposal::create([
            'design_project_id' => $projectId,
            'team_id' => $teamId,
            'user_id' => $user->id,
            'mode' => 'copy',
            'status' => $status,
            'prompt' => mb_substr($kind.': '.($clean['product'] ?? $clean['business_name'] ?? ''), 0, 2000),
            'payload' => ['kind' => $kind, 'suggestions' => $kept, 'dropped' => $dropped],
            'model' => $call->model ?? (string) config('design_ai.model'),
            'tokens_in' => $call->tokensIn,
            'tokens_out' => $call->tokensOut,
            'error' => $kept === [] ? 'no_safe_suggestions' : null,
        ]);

        if ($kept === []) {
            return ['status' => 'nothing', 'dropped' => $dropped, 'message' => 'None of the suggestions were safe to use. They had numbers or contact details you did not give, or they were too long. Add the details to the brief and try again.'];
        }

        return [
            'status' => 'ok',
            'proposal_id' => $row->id,
            'dropped' => $dropped,
            'suggestions' => array_map(fn (string $text, int $i) => ['id' => $i + 1, 'text' => $text], $kept, array_keys($kept)),
        ];
    }

    /**
     * @param  list<mixed>  $raw
     * @return array{0: list<string>, 1: int}
     */
    private function filter(array $raw, int $max, int $count, string $source, ?BrandKit $kit): array
    {
        $avoid = array_filter(array_map(fn ($w) => is_string($w) ? mb_strtolower(trim($w)) : '', (array) (($kit?->voice ?? [])['avoid'] ?? [])));
        $kept = [];
        $dropped = 0;

        foreach ($raw as $item) {
            $text = TextGuard::clean($item, $max, true);

            $bad = $text === null
                || ! TextGuard::supported($text, $source)
                || $this->containsAny($text, $avoid)
                || in_array(mb_strtolower($text), array_map('mb_strtolower', $kept), true);

            if ($bad) {
                $dropped++;

                continue;
            }

            if (count($kept) < $count) {
                $kept[] = $text;
            }
        }

        return [$kept, $dropped];
    }

    /** @param list<string> $words */
    private function containsAny(string $text, array $words): bool
    {
        $lower = mb_strtolower($text);

        foreach ($words as $word) {
            if ($word !== '' && preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $lower)) {
                return true;
            }
        }

        return false;
    }
}
