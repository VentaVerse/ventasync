<?php

namespace Extensions\VentaCart\Controllers;

class VentaCartDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function channel(): string
    {
        return 'ventacart';
    }
}
