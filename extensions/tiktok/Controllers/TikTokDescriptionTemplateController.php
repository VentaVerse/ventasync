<?php

namespace Extensions\TikTok\Controllers;

class TikTokDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function channel(): string
    {
        return 'tiktok';
    }
}
