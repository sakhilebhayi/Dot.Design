<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Contracts\ReportsStatus;
use Throwable;

/**
 * The one honest answer to "which other Dot platforms is Dot.Design really connected to?". Consumed by the settings page and by
 * GET design/integrations/status. It combines what each adapter reports with the platforms that expose no usable contract, which
 * are shown as "Not connected" with the reason and never with a control.
 */
class IntegrationStatus
{
    /** @param  iterable<ReportsStatus>  $adapters */
    public function __construct(private readonly iterable $adapters) {}

    /** @return list<IntegrationState> */
    public function all(): array
    {
        $states = [];

        foreach ($this->adapters as $adapter) {
            try {
                $states[] = $adapter->status();
            } catch (Throwable) {
                // a status check must never break the page
            }
        }

        foreach ((array) config('design_integrations.no_contract', []) as $key => $info) {
            $states[] = new IntegrationState((string) $key, (string) $info['name'], IntegrationState::NO_CONTRACT,
                'Nothing: there is no connection to build on.', (string) $info['reason']);
        }

        return $states;
    }

    /** @return list<array<string, string|null>> */
    public function toArray(bool $withSetup = false): array
    {
        return array_map(fn (IntegrationState $s) => $s->toArray($withSetup), $this->all());
    }
}
