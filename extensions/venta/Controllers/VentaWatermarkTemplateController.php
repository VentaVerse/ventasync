<?php

namespace Extensions\venta\Controllers;

/**
 * Watermark templates for one Venta store. The shared behaviour lives in
 * App\Http\Controllers\Channels\WatermarkTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (venta/watermark_template) rather than on one cross-channel key.
 */
class VentaWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'venta';
    }
}
