<?php

namespace Extensions\lazada\Controllers;

class LazadaDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'lazada';
    }
}
