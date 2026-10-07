<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Integrations\ChannelDashboardController;

class VentaDashboardController extends ChannelDashboardController
{
    protected string $channel = 'venta';
}
