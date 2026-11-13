<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one plain page a person sees when their company does not hold Dot.Design (R-50): what happened, in the words of
 * InfoDot's status, and the one place to sort it out. It carries no design, no project and no workspace data.
 */
final class EntitlementRefusal
{
    private function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
    ) {}

    /** From the status InfoDot's read endpoint gives for this platform's own row (during a session). */
    public static function fromStatus(string $status, ?string $company = null): self
    {
        return self::worded(match ($status) {
            'suspended' => 'suspended',
            'payment_required' => 'payment',
            'subscription_expired' => 'ended',
            default => 'off',
        }, $company);
    }

    /** From the reason InfoDot's redeem answer gives for a company (at sign-in). */
    public static function fromReason(?string $reason): self
    {
        return self::worded(match ($reason) {
            'suspended' => 'suspended',
            'payment_required' => 'payment',
            'expired', 'trial_ended', 'cancelled' => 'ended',
            default => 'off',
        }, null);
    }

    private static function worded(string $kind, ?string $company): self
    {
        $for = filled($company) ? $company : 'your company';
        $kept = 'Your designs are kept exactly as they are.';
        $who = 'A company owner or admin can';

        return match ($kind) {
            'suspended' => new self($kind, "Dot.Design is suspended for {$for}", "Access to Dot.Design has been suspended for this company. {$kept} {$who} see why in InfoDot."),
            'payment' => new self($kind, "Dot.Design needs a payment for {$for}", "A payment is due before this company can keep using Dot.Design. {$kept} {$who} settle it in InfoDot."),
            'ended' => new self($kind, "Dot.Design has ended for {$for}", "This company's time on Dot.Design has ended. {$kept} {$who} switch it on again in InfoDot."),
            default => new self($kind, "Dot.Design is not switched on for {$for}", "This company does not have Dot.Design switched on. {$who} switch it on in InfoDot."),
        };
    }

    public function toResponse(Request $request, ?User $user = null): Response
    {
        $entitlement = app(CompanyEntitlement::class);
        $headers = ['Cache-Control' => 'no-store, private'];

        if ($request->expectsJson()) {
            return response()->json(['message' => $this->title.'.', 'reason' => 'platform_not_held', 'manage_url' => $entitlement->manageUrl()], 403, $headers);
        }

        return response()->view('errors.entitlement', [
            'refusal' => $this,
            'manageUrl' => $entitlement->manageUrl(),
            'workspaces' => $user ? $this->otherWorkspaces($user, $entitlement) : collect(),
        ], 403, $headers);
    }

    /**
     * The person's own other workspaces, by name only, leaving out any InfoDot has already said "no" for. Nothing is
     * asked of InfoDot here: the workspace they pick is checked when they arrive in it.
     *
     * @return Collection<int, Team>
     */
    private function otherWorkspaces(User $user, CompanyEntitlement $entitlement): Collection
    {
        return $user->allTeams()
            ->reject(fn ($team) => $team->id === $user->current_team_id)
            ->reject(fn ($team) => filled($team->infodot_team_uuid) && ($entitlement->known((string) $team->infodot_team_uuid)['entitled'] ?? null) === false)
            ->values();
    }
}
