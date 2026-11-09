<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\InfoDot\ApplyMembershipChange;
use App\Services\Integrations\InfoDot\SignedMembershipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * InfoDot tells this app, signed, that a company's membership changed (POST /api/ecosystem/membership). Every refusal looks
 * the same: switched off, no secret, bad or stale signature all answer 401 `invalid_request`, so a caller learns nothing.
 */
class MembershipWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyMembershipChange $apply): JsonResponse
    {
        if (! config('design_integrations.membership.enabled') || ! SignedMembershipRequest::valid($request)) {
            Log::warning('Membership webhook refused.', ['reason' => 'switched off, not configured, or bad or stale signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        // A signed request is good for five minutes, so a captured one could be replayed inside that window (an old role change
        // landing after a newer one). The signature is remembered a little longer than the window: a second delivery of the
        // same request is acknowledged and not applied again (R-45 security S-10).
        $seen = 'infodot-membership:'.hash('sha256', (string) $request->header('X-InfoDot-Signature'));

        if (! Cache::add($seen, 1, 900)) {
            return response()->json(['ok' => true], 202);
        }

        $change = Validator::make((array) json_decode($request->getContent(), true), [
            'event' => ['required', 'in:member.removed,member.role_changed,team.deleted'],
            'team_uuid' => ['required', 'uuid'],
            'email' => ['nullable', 'email'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        if ($change->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $apply($change->validated());

        return response()->json(['ok' => true], 202);
    }
}
