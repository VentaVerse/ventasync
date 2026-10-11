<?php

namespace Extensions\Shopee\Controllers;

class ShopeeDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function channel(): string
    {
        return 'shopee';
    }
}
