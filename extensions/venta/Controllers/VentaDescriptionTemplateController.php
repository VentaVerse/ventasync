<?php

namespace Extensions\venta\Controllers;

class VentaDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'venta';
    }
}
