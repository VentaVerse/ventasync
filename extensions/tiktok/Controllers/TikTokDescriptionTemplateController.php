<?php

namespace Extensions\tiktok\Controllers;

/**
 * Description templates for one TikTok Shop store. The shared behaviour lives in
 * App\Http\Controllers\Channels\DescriptionTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (tiktok/description_template) rather than on one cross-channel key.
 */
class TikTokDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'tiktok';
    }
}
