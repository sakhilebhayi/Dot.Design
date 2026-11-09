<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\InfoDot\ApplyMembershipChange;
use App\Services\Integrations\InfoDot\SignedMembershipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
