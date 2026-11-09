<?php

namespace App\Services\Integrations\InfoDot;

use App\Events\EcosystemMemberRemoved;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Jetstream;

/**
 * Applies a membership change InfoDot has just made, so access ends in seconds rather than at the person's next sign-in (which
 * still catches anything this misses).
 *
 * - member.removed: detach the person from the linked workspace and clear it as their current team if it was.
 * - member.role_changed: set the person's role here, with the same mapping as sign-in (owner/admin -> admin, editor -> editor).
 * - team.deleted: unlink the workspace and detach everyone but its local owner. Designs are never deleted as a side effect.
 *
 * A workspace's local owner is never detached. Unknown teams and people are ignored without a word, so the caller learns nothing
 * about who exists here. Every event is idempotent.
 */
class ApplyMembershipChange
{
    /** @param  array{event: string, team_uuid: string, email?: string|null, role?: string|null}  $change */
    public function __invoke(array $change): void
    {
        $team = Team::where('infodot_team_uuid', $change['team_uuid'])->first();

        if (! $team) {
            return;
        }

        DB::transaction(function () use ($team, $change) {
            match ($change['event']) {
                'member.removed' => $this->remove($team, $this->person($change), 'member.removed'),
                'member.role_changed' => $this->changeRole($team, $this->person($change), (string) ($change['role'] ?? '')),
                'team.deleted' => $this->unlink($team),
                default => null,
            };
        });
    }

    /** @param  array<string, mixed>  $change */
    private function person(array $change): ?User
    {
        return filled($change['email'] ?? null)
            ? User::whereRaw('lower(email) = ?', [strtolower((string) $change['email'])])->first()
            : null;
    }

    private function remove(Team $team, ?User $user, string $reason): void
    {
        if (! $user) {
            return;
        }

        if ($team->user_id === $user->id) {
            Log::warning('InfoDot removed the local owner of a workspace; left in place.', ['team_id' => $team->id, 'user_id' => $user->id]);

            return;
        }

        if (! $team->users()->where('users.id', $user->id)->exists()) {
            return;
        }

        $team->users()->detach($user->id);

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        EcosystemMemberRemoved::dispatch($team->id, $user->id, $reason);
    }

    private function changeRole(Team $team, ?User $user, string $infodotRole): void
    {
        if (! $user || $team->user_id === $user->id || ! $team->users()->where('users.id', $user->id)->exists()) {
            return;
        }

        $team->users()->updateExistingPivot($user->id, ['role' => $this->localRole($infodotRole)]);
    }

    private function unlink(Team $team): void
    {
        foreach ($team->users()->get() as $member) {
            if ($member instanceof User) {
                $this->remove($team, $member, 'team.deleted');
            }
        }

        $team->forceFill(['infodot_team_uuid' => null])->save();
    }

    private function localRole(string $infodotRole): string
    {
        $wanted = in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');

        return Jetstream::findRole($wanted) ? $wanted : 'editor';
    }
}
