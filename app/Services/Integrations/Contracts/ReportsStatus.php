<?php

namespace App\Services\Integrations\Contracts;

use App\Services\Integrations\IntegrationState;

/** Implemented by every adapter so the settings page can say, honestly, what state it is in. */
interface ReportsStatus
{
    public function status(): IntegrationState;
}
