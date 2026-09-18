<?php

namespace Extensions\shopee\Controllers;

/**
 * Watermark templates for one Shopee store. The shared behaviour lives in
 * App\Http\Controllers\Channels\WatermarkTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (shopee/watermark_template) rather than on one cross-channel key.
 */
class ShopeeWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'shopee';
    }
}
