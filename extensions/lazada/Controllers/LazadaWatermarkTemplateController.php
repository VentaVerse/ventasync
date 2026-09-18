<?php

namespace Extensions\lazada\Controllers;

/**
 * Watermark templates for one Lazada store. The shared behaviour lives in
 * App\Http\Controllers\Channels\WatermarkTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (lazada/watermark_template) rather than on one cross-channel key.
 */
class LazadaWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'lazada';
    }
}
