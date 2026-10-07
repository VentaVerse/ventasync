<?php

namespace Extensions\tiktok\Controllers;

class TikTokDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'tiktok';
    }
}
