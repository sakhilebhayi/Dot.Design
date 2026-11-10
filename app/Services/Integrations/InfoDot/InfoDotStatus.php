<?php

namespace App\Services\Integrations\InfoDot;

use App\Services\InfoDot\IdentityClient;
use App\Services\Integrations\Contracts\ReportsStatus;
use App\Services\Integrations\IntegrationState;

/**
 * Status of the InfoDot integrations that already exist (sign-in and company sync via the redeem call) and of the membership
 * webhook added here. Reads configuration only: it never calls InfoDot, so it can say "set up" but not "proven".
 */
class InfoDotStatus implements ReportsStatus
{
    public function __construct(private readonly IdentityClient $identity) {}

    public function status(): IntegrationState
    {
        $does = 'Signs you in with your InfoDot account, keeps your businesses and roles in step with InfoDot, and removes access when someone leaves a business.';
        $setup = 'Set INFODOT_SERVICE_SECRET (the same value as ECOSYSTEM_SECRET_DESIGN in InfoDot). For instant removal also set DESIGN_INTEGRATION_MEMBERSHIP=true and add `design` to ECOSYSTEM_MEMBERSHIP_PLATFORMS in InfoDot.';

        if (! $this->identity->enabled()) {
            return new IntegrationState('infodot', 'InfoDot', IntegrationState::NOT_CONFIGURED, $does,
                'Sign-in through InfoDot is not set up on this server, so businesses are not synced.', $setup);
        }

        $instant = config('design_integrations.membership.enabled')
            ? 'Removing someone from a business in InfoDot ends their access here at once.'
            : 'Removing someone from a business in InfoDot ends their access here the next time they sign in.';

        return new IntegrationState('infodot', 'InfoDot', IntegrationState::READY, $does,
            'Sign-in and business sync are set up. '.$instant, $setup);
    }
}
