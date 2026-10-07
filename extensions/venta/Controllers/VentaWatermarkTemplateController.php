<?php

namespace Extensions\venta\Controllers;

class VentaWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'venta';
    }
}
