<?php

namespace App\Services\Integrations\Notify;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Models\DesignProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Decides who is told what, from the plain values a design event carries. Nothing here sends anything.
 *
 * Rules that keep it honest and quiet:
 * - Only people who belong to the workspace the event happened in are addressed, and never the person who did the thing.
 * - A workspace that is not linked to an InfoDot company has no company_uuid Notify could file the event under, so nobody
 *   is addressed (a personal workspace therefore sends nothing).
 * - The data carries the design's name, the actor's display name and a link: never comment text, notes or addresses.
 * - The idempotency key is "{event}:{subject}:{sha1(recipient)}", so a retried listener or a double dispatch collapses in
 *   Notify while a genuinely different comment, request or share does not.
 */
class NotificationPlanner
{
    /** @return list<DesignNotification> */
    public function commentAdded(DesignCommentAdded $e): array
    {
        // A guest comment through a share link has no member to credit and nobody to address by name: skipped.
        if ($e->comment->user_id === null) {
            return [];
        }

        $recipients = array_merge([$e->project->user_id], $e->mentioned);

        return $this->build(DesignNotifyEvents::COMMENT_ADDED, $e->project, null, (int) $e->comment->user_id, $recipients, 'c'.$e->comment->id, fn (User $actor) => ['commented_by' => $actor->name]);
    }

    /** @return list<DesignNotification> */
    public function reviewRequested(DesignReviewRequested $e): array
    {
        $review = $e->review;

        return $this->build(DesignNotifyEvents::REVIEW_REQUESTED, $this->project((int) $review->design_project_id), null, (int) $review->requested_by, [(int) $review->reviewer_id], 'r'.$review->id, fn (User $actor) => ['requested_by' => $actor->name]);
    }

    /** @return list<DesignNotification> */
    public function reviewDecided(DesignReviewDecided $e): array
    {
        $review = $e->review;
        $status = $review->status instanceof \BackedEnum ? (string) $review->status->value : (string) $review->status;
        $decision = match ($status) {
            'approved' => 'Approved',
            'changes_requested' => 'Changes requested',
            default => Str::ucfirst(str_replace('_', ' ', $status)),
        };

        return $this->build(DesignNotifyEvents::REVIEW_DECIDED, $this->project((int) $review->design_project_id), null, (int) $review->reviewer_id, [(int) $review->requested_by], 'r'.$review->id.':'.$status, fn (User $actor) => ['decided_by' => $actor->name, 'decision' => $decision]);
    }

    /** @return list<DesignNotification> A share link has no named member recipients, so nobody is addressed. */
    public function shared(DesignShared $e): array
    {
        return [];
    }

    /**
     * @param  list<int>  $recipientIds
     * @param  \Closure(User): array<string, string>  $actorData
     * @return list<DesignNotification>
     */
    private function build(string $event, ?DesignProject $project, ?int $teamId, int $actorId, array $recipientIds, string $subject, \Closure $actorData): array
    {
        if ($project === null) {
            return [];
        }

        $team = $this->team($teamId ?? $project->getAttribute('team_id'));
        $actor = User::find($actorId);

        if ($team === null || $actor === null) {
            return [];
        }

        $data = $this->filter($event, ['design_name' => $project->name, 'url' => $this->url($project)] + $actorData($actor));
        $out = [];

        foreach (array_unique(array_map('intval', $recipientIds)) as $id) {
            if ($id === $actor->id) {
                continue;
            }

            $user = User::find($id);

            if ($user === null || ! filled($user->email) || ! $team->hasUser($user)) {
                continue;
            }

            $out[] = new DesignNotification($event, (string) $team->infodot_team_uuid, (string) $user->email, $data,
                $event.':'.$subject.':'.sha1(strtolower(trim((string) $user->email))));
        }

        return $out;
    }

    private function project(int $id): ?DesignProject
    {
        // The explicit workspace-membership check in build() is the access rule here: this runs on a queue worker with no signed-in user.
        return DesignProject::withoutGlobalScopes()->find($id);
    }

    private function team(mixed $id): ?Team
    {
        if (! is_numeric($id)) {
            return null;
        }

        $team = Team::find((int) $id);

        return $team !== null && filled($team->infodot_team_uuid) ? $team : null;
    }

    /** Only the catalogue keys, every value a plain string. @param  array<string, mixed>  $data @return array<string, string> */
    private function filter(string $event, array $data): array
    {
        $allowed = DesignNotifyEvents::dataKeys()[$event] ?? [];

        return collect($allowed)
            ->filter(fn (string $key) => array_key_exists($key, $data))
            ->mapWithKeys(fn (string $key) => [$key => $data[$key]])
            ->map(fn ($v) => Str::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? ''), 200, '...'))
            ->all();
    }

    private function url(DesignProject $project): string
    {
        $url = Route::has('design.projects.show') ? route('design.projects.show', $project) : url('/design/projects/'.$project->getKey());

        return preg_replace('#^http://#i', 'https://', $url) ?? $url;
    }
}
