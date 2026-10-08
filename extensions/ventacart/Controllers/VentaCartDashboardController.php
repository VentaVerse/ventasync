<?php

namespace Extensions\VentaCart\Controllers;

use App\Http\Controllers\Integrations\ChannelDashboardController;

class VentaCartDashboardController extends ChannelDashboardController
{
    protected string $channel = 'ventacart';
}
