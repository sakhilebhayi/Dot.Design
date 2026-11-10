<?php

namespace App\Services\Integrations\Analytics;

use App\Services\Analytics\AnalyticsClient;
use App\Services\Integrations\Contracts\ReportsStatus;
use App\Services\Integrations\IntegrationState;

/**
 * Status of the existing daily snapshot to Dot.Analytics (App\Services\Analytics). Reads configuration and the existing circuit
 * breaker only. Dot.Analytics' catalogue for `design` accepts exactly two numbers (projects, assets), so that is all that is sent.
 */
class AnalyticsStatus implements ReportsStatus
{
    public function __construct(private readonly AnalyticsClient $client) {}

    public function status(): IntegrationState
    {
        $does = 'Sends Dot.Analytics one aggregate number a day for the whole platform: how many design projects and assets exist. No names, files or people.';
        $setup = 'Set ANALYTICS_INGEST_URL (ending /api/ingest/snapshots), ANALYTICS_INGEST_SECRET (the same value as ANALYTICS_INGEST_SECRET_DESIGN in Dot.Analytics) and ANALYTICS_COMPANY_UUID.';

        if (! $this->client->enabled()) {
            return new IntegrationState('analytics', 'Dot.Analytics', IntegrationState::NOT_CONFIGURED, $does,
                'Daily numbers are not switched on for this server yet.', $setup);
        }

        if ($this->client->circuitOpen()) {
            return new IntegrationState('analytics', 'Dot.Analytics', IntegrationState::UNAVAILABLE, $does,
                'Dot.Analytics did not answer several times in a row, so the daily numbers are paused for a few minutes.', $setup);
        }

        return new IntegrationState('analytics', 'Dot.Analytics', IntegrationState::READY, $does,
            'Set up. A daily number is sent after midnight UTC; this page cannot show whether the last one arrived.', $setup);
    }
}
