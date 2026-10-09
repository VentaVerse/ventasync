<?php

namespace Extensions\Lazada\Controllers;

class LazadaDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function channel(): string
    {
        return 'lazada';
    }
}
