<?php

namespace Extensions\Lazada\Controllers;

class LazadaWatermarkTemplateController extends \App\Http\Controllers\Channels\WatermarkTemplateController
{
    protected function channel(): string
    {
        return 'lazada';
    }
}
