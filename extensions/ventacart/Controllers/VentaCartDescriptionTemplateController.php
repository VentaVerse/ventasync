<?php

namespace Extensions\VentaCart\Controllers;

class VentaCartDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'ventacart';
    }
}
