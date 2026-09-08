<?php

namespace Extensions\shopee\Controllers;

/**
 * Description templates for one Shopee store. The shared behaviour lives in
 * App\Http\Controllers\Channels\DescriptionTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (shopee/description_template) rather than on one cross-channel key.
 */
class ShopeeDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'shopee';
    }
}
