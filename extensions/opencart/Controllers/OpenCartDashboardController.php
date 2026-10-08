<?php

namespace Extensions\OpenCart\Controllers;

use App\Http\Controllers\Integrations\ChannelDashboardController;

class OpenCartDashboardController extends ChannelDashboardController
{
    protected string $channel = 'opencart';
}
