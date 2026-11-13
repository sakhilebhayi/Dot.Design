<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in studio is for companies that hold Dot.Design (R-50). InfoDot decides; this asks it about the person's
 * CURRENT company (the workspace linked to an InfoDot company) and keeps the answer for a few minutes.
 *
 * Refuses only on InfoDot's explicit "no". An unknown answer of any kind lets the request through unchanged, so an
 * outage or a misconfiguration never locks a paying company out. A workspace that is not linked to an InfoDot company
 * (a personal workspace) is not asked about: sign-in already decided whether the person may come in.
 *
 * It sits on the signed-in route group only. Sign-in and sign-out, the handoff receiver, the health check, webhooks,
 * the public share and review links, the legal pages, and Jetstream's own account pages (profile, workspace settings,
 * the workspace switch) are outside that group and are never checked.
 */
class EnsureCompanyEntitled
{
    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return $next($request);
        }

        // One primary-key read, handed to the user so the page that follows does not read the workspace again.
        if (! $user->relationLoaded('currentTeam')) {
            $user->setRelation('currentTeam', Team::find($user->current_team_id));
        }

        $company = $user->currentTeam;
        $uuid = $company instanceof Team ? $company->infodot_team_uuid : null;

        if (! $company instanceof Team || ! filled($uuid)) {
            return $next($request);
        }

        $verdict = $this->entitlement->verdict((string) $uuid);

        if ($verdict === null || $verdict['entitled'] === true) {
            return $next($request);
        }

        return EntitlementRefusal::fromStatus($verdict['status'], $company->name)->toResponse($request, $user);
    }
}
