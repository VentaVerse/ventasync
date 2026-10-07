<?php

namespace Extensions\tiktok\Controllers;

class TikTokWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'tiktok';
    }
}
