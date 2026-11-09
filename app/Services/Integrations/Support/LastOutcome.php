<?php

namespace App\Services\Integrations\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The outcome of the most recent real call to another platform, kept for the settings page so "connected" is earned by a
 * call that actually succeeded rather than assumed from configuration. Holds a timestamp, a boolean and a plain-language
 * message; never a payload, address, URL or secret.
 */
final class LastOutcome
{
    public function __construct(private readonly string $name) {}

    public function remember(bool $ok, ?string $message = null): void
    {
        try {
            Cache::put($this->key(), ['at' => now()->toIso8601String(), 'ok' => $ok, 'message' => $message], now()->addDays(30));
        } catch (Throwable) {
            // a courtesy for the settings page; losing it must not matter
        }
    }

    /** @return array{at: string, ok: bool, message: string|null}|null */
    public function get(): ?array
    {
        try {
            $value = Cache::get($this->key());

            return is_array($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function key(): string
    {
        return 'design:integration:'.$this->name.':last';
    }
}
