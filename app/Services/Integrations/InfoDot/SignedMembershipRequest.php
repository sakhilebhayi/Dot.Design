<?php

namespace App\Services\Integrations\InfoDot;

use Illuminate\Http\Request;

/**
 * Is this request really from InfoDot, and fresh? InfoDot signs "{timestamp}.{raw body}" with HMAC-SHA256 using this platform's
 * INFODOT_SERVICE_SECRET (the secret it already redeems handoff tokens with), sent as "sha256=<hex>" in X-InfoDot-Signature
 * with the unix time in X-InfoDot-Timestamp. Only InfoDot can make it, a body cannot be swapped under a signature, and a captured
 * request goes stale in five minutes. Fails closed: no secret configured means nothing is valid.
 */
final class SignedMembershipRequest
{
    private const TOLERANCE = 300;

    public static function valid(Request $request): bool
    {
        $secret = (string) config('ecosystem.infodot.secret');
        $timestamp = (int) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        if ($secret === '' || $timestamp === 0 || abs(time() - $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }
}
