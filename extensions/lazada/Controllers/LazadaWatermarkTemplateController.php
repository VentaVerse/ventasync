<?php

namespace Extensions\lazada\Controllers;

class LazadaWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function integration(): string
    {
        return 'lazada';
    }
}
