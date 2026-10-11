<?php

namespace Extensions\TikTok\Controllers;

class TikTokWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function channel(): string
    {
        return 'tiktok';
    }
}
