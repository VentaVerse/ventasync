<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Integrations\ChannelDashboardController;

/**
 * The Venta landing page. All logic is in the shared base; this subclass
 * exists so the route - and therefore the derived permission key
 * (venta/dashboard) - belongs to this extension. See the base class for why
 * core's /{channel} wildcard could not do this job.
 */
class VentaDashboardController extends ChannelDashboardController
{
    protected string $channel = 'venta';
}
