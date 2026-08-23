<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Integrations\ChannelDashboardController;

/**
 * The OpenCart landing page. All logic is in the shared base; this subclass
 * exists so the route - and therefore the derived permission key
 * (opencart/dashboard) - belongs to this extension. See the base class for why
 * core's /{channel} wildcard could not do this job.
 */
class OpenCartDashboardController extends ChannelDashboardController
{
    protected string $channel = 'opencart';
}
