<?php

namespace App\Services\Integrations;

/**
 * What the settings page and the status endpoint say about one platform.
 *
 *   connected       configured, and the last real call succeeded
 *   ready           configured, but no call has been made yet (not proven)
 *   unavailable     configured, but the last call failed or the integration is paused after repeated failures
 *   not_configured  a real contract exists, but it is switched off or missing a url/secret here
 *   no_contract     the other platform exposes nothing Dot.Design can use
 */
final class IntegrationState
{
    public const CONNECTED = 'connected';

    public const READY = 'ready';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_CONFIGURED = 'not_configured';

    public const NO_CONTRACT = 'no_contract';

    /**
     * @param  string  $key  platform key as in config/ecosystem.php
     * @param  string  $does  what it does for people, in one sentence
     * @param  string  $reason  why it is in this state, in plain language
     * @param  string|null  $setup  what an operator must do (shown to platform operators only)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $status,
        public readonly string $does,
        public readonly string $reason,
        public readonly ?string $setup = null,
    ) {}

    public function label(): string
    {
        return match ($this->status) {
            self::CONNECTED => 'Connected',
            self::READY => 'Set up, not used yet',
            self::UNAVAILABLE => 'Unavailable right now',
            self::NOT_CONFIGURED => 'Not connected',
            default => 'Not connected',
        };
    }

    /** @return array<string, string|null> */
    public function toArray(bool $withSetup = false): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'status' => $this->status,
            'label' => $this->label(),
            'does' => $this->does,
            'reason' => $this->reason,
        ] + ($withSetup ? ['setup' => $this->setup] : []);
    }
}
