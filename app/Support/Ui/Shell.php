<?php

namespace App\Support\Ui;

use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\DesignAccess;
use App\Support\Design\ReviewStatus;
use Dot\Ui\PlatformSwitcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;

/**
 * What the shared dot-ui header (resources/views/layouts/app.blade.php) shows for the signed-in person: the account menu rows
 * (workspace switch, workspace settings, studio settings, profile, tokens), the bell count and the destinations the phone drawer
 * lists. One place, so the drawer on a phone and the account menu on a desktop can never disagree, and a test can read it
 * without rendering a page.
 */
class Shell
{
    /**
     * Reviews that ask this person for a decision. The bell is a link to the review inbox, the count is that inbox's pending
     * rows (the same rows the dashboard's "waiting on you" list reads), never a number made up for the header.
     */
    public static function waitingReviews(User $user): int
    {
        return DesignReview::query()
            ->where('reviewer_id', $user->id)
            ->where('status', ReviewStatus::Pending->value)
            ->whereHas('project')
            ->count();
    }

    /**
     * The view model of the Dot Apps switcher. <x-dot.switcher> prints nothing when it cannot build one (its read of InfoDot throws),
     * which on a phone would leave the header with no way to change platform; this falls back to the host-owned "open through
     * InfoDot" list instead (PlatformSwitcher::plain), which claims nothing about entitlements.
     *
     * @return array<string, mixed>
     */
    public static function switcher(?string $company): array
    {
        try {
            return PlatformSwitcher::fromConfig('design')->for($company);
        } catch (\Throwable) {
            return PlatformSwitcher::plain('design');
        }
    }

    /**
     * @return array{name: string, email: ?string, team: ?string, avatar: ?string, links: list<array<string, mixed>>, signOut: array{action: string}, theme: bool}
     */
    public static function account(User $user, ?Request $request = null): array
    {
        $team = $user->currentTeam;
        $links = [];

        if (Jetstream::hasTeamFeatures()) {
            $teams = $user->allTeams();

            if ($teams->count() > 1) {
                $links[] = ['heading' => 'Switch workspace'];
                foreach ($teams as $other) {
                    $links[] = [
                        'label' => $other->name,
                        'current' => $team !== null && $other->id === $team->id,
                        'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $other->id]],
                    ];
                }
                $links[] = ['rule' => true];
            }

            if ($team !== null && Route::has('teams.show')) {
                $links[] = ['label' => 'Workspace settings', 'href' => route('teams.show', $team->id)];
            }
            if ($team !== null && Route::has('design.activity.page') && in_array(DesignAccess::teamRole($user->id, $team->id), ['owner', 'admin'], true)) {
                $links[] = ['label' => 'Activity', 'href' => route('design.activity.page', ['team_id' => $team->id])];
            }
            if (Route::has('teams.create') && $user->can('create', Jetstream::newTeamModel())) {
                $links[] = ['label' => 'Create workspace', 'href' => route('teams.create')];
            }
            $links[] = ['rule' => true];
        }

        if (Route::has('design.settings')) {
            $links[] = ['label' => 'Studio settings', 'href' => route('design.settings')];
        }
        if (Route::has('profile.show')) {
            $links[] = ['label' => 'Profile and security', 'href' => route('profile.show')];
        }
        if (Route::has('api-tokens.index') && Jetstream::hasApiFeatures()) {
            $links[] = ['label' => 'API tokens', 'href' => route('api-tokens.index')];
        }

        // a rule may not lead, trail or double
        $clean = [];
        foreach ($links as $link) {
            $isRule = ! empty($link['rule']);
            if ($isRule && ($clean === [] || ! empty(end($clean)['rule']))) {
                continue;
            }
            $clean[] = $link;
        }
        while ($clean !== [] && ! empty(end($clean)['rule'])) {
            array_pop($clean);
        }

        return [
            'name' => (string) $user->name,
            'email' => $user->email,
            'team' => $team?->name,
            'avatar' => $user->profile_photo_path ? $user->profile_photo_url : null,
            'links' => $clean,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ];
    }
}
