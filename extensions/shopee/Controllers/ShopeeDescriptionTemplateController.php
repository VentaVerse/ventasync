<?php

namespace Extensions\Shopee\Controllers;

class ShopeeDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'shopee';
    }
}
