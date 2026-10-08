<?php

namespace Extensions\TikTok\Controllers;

class TikTokDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'tiktok';
    }
}
