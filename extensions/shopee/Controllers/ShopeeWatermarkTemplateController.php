<?php

namespace Extensions\shopee\Controllers;

class ShopeeWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'shopee';
    }
}
