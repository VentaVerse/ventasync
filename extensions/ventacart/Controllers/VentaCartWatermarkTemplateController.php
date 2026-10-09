<?php

namespace Extensions\VentaCart\Controllers;

class VentaCartWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function channel(): string
    {
        return 'ventacart';
    }
}
