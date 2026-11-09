<?php

namespace App\Services\Integrations\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A small, cache-backed circuit breaker: after `threshold` transient failures in a row the integration is left alone for
 * `cooldown` seconds, then one trial call goes through. The failure count survives the pause, so a failed trial reopens it
 * at once; only a success starts the count over. A broken cache must never stop (or break) a call, so every cache error is
 * swallowed and the breaker simply reads as closed.
 */
final class CircuitBreaker
{
    public function __construct(private readonly string $name) {}

    public function isOpen(): bool
    {
        try {
            return (bool) Cache::get($this->key('open'), false);
        } catch (Throwable) {
            return false;
        }
    }

    public function recordFailure(): void
    {
        try {
            $threshold = max(1, (int) config('design_integrations.breaker.threshold', 5));
            $cooldown = max(1, (int) config('design_integrations.breaker.cooldown', 120));

            Cache::add($this->key('failures'), 0, now()->addDay());
            $failures = (int) Cache::increment($this->key('failures'));

            if ($failures >= $threshold) {
                Cache::put($this->key('open'), true, now()->addSeconds($cooldown));
                Log::warning('A Dot integration was paused after repeated failures.', ['integration' => $this->name, 'cooldown_seconds' => $cooldown]);
            }
        } catch (Throwable) {
            // see isOpen()
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget($this->key('failures'));
            Cache::forget($this->key('open'));
        } catch (Throwable) {
            // see isOpen()
        }
    }

    private function key(string $part): string
    {
        return 'design:integration:'.$this->name.':breaker:'.$part;
    }
}
