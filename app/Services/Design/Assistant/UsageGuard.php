<?php

namespace App\Services\Design\Assistant;

use App\Models\DesignAiProposal;
use App\Models\User;
use App\Services\Design\Assistant\Exceptions\AssistantFailed;
use App\Services\Design\Assistant\Exceptions\AssistantLimitReached;
use Illuminate\Support\Carbon;

/**
 * Daily caps on calls to the model, per person and per workspace. The count is the rows that record a call
 * (design_ai_proposals.model is set), so it survives cache flushes and shows up in the audit data. Automatic fixes make
 * no call and are never counted.
 */
class UsageGuard
{
    /** @throws AssistantLimitReached */
    public function check(User $user, ?int $teamId): void
    {
        $usage = $this->usage($user, $teamId);

        if ($usage['user']['used'] >= $usage['user']['limit']) {
            throw new AssistantLimitReached(
                "You have used all {$usage['user']['limit']} assistant requests for today. Automatic fixes still work, and the limit resets at midnight.",
                'user',
                $usage['user']['limit'],
            );
        }

        if ($usage['workspace']['limit'] > 0 && $usage['workspace']['used'] >= $usage['workspace']['limit']) {
            throw new AssistantLimitReached(
                'Your workspace has used its assistant requests for today. Automatic fixes still work, and the limit resets at midnight.',
                'workspace',
                $usage['workspace']['limit'],
            );
        }
    }

    /** @return array{user: array{used: int, limit: int}, workspace: array{used: int, limit: int}} */
    public function usage(User $user, ?int $teamId): array
    {
        $since = Carbon::now()->startOfDay();
        $calls = fn () => DesignAiProposal::query()->whereNotNull('model')->where('created_at', '>=', $since);

        return [
            'user' => ['used' => $calls()->where('user_id', $user->id)->count(), 'limit' => (int) config('design_ai.limits.user_daily')],
            'workspace' => [
                'used' => $teamId === null ? 0 : $calls()->where('team_id', $teamId)->count(),
                'limit' => $teamId === null ? 0 : (int) config('design_ai.limits.workspace_daily'),
            ],
        ];
    }

    /**
     * Record a call that failed after a request was made (so it counts), or return quietly for a refused one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordFailure(User $user, ?int $teamId, ?int $projectId, string $mode, ?string $prompt, AssistantFailed $failure): void
    {
        if (! $failure->attempted) {
            return;
        }

        DesignAiProposal::create([
            'design_project_id' => $projectId,
            'team_id' => $teamId,
            'user_id' => $user->id,
            'mode' => $mode,
            'status' => 'failed',
            'prompt' => $prompt === null ? null : mb_substr($prompt, 0, 2000),
            'model' => (string) config('design_ai.model'),
            'error' => mb_substr($failure->reason, 0, 120),
        ]);
    }
}
