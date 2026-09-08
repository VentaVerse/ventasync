<?php

namespace Extensions\lazada\Controllers;

/**
 * Description templates for one Lazada store. The shared behaviour lives in
 * App\Http\Controllers\Channels\DescriptionTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (lazada/description_template) rather than on one cross-channel key.
 */
class LazadaDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'lazada';
    }
}
