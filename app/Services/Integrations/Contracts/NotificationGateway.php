<?php

namespace App\Services\Integrations\Contracts;

use App\Services\Integrations\Notify\DesignNotification;

/**
 * Tells the people of a workspace something happened to a design. The only implementation talks to Dot.Notify; with it
 * absent or switched off, `enabled()` is false and callers do nothing.
 */
interface NotificationGateway extends ReportsStatus
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    public function enabled(): bool;

    /** Send one notification now. Returns DONE, RETRY (try again later) or GIVE_UP (retrying cannot help). Never throws. */
    public function deliver(DesignNotification $notification): string;
}
