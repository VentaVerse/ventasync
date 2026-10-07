<?php

namespace Extensions\ventacart\Controllers;

class VentaCartWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'ventacart';
    }
}
