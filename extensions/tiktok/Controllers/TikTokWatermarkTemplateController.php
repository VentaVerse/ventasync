<?php

namespace Extensions\tiktok\Controllers;

/**
 * Watermark templates for one TikTok Shop store. The shared behaviour lives in
 * App\Http\Controllers\Channels\WatermarkTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (tiktok/watermark_template) rather than on one cross-channel key.
 */
class TikTokWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'tiktok';
    }
}
