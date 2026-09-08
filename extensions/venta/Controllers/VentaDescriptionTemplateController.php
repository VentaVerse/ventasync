<?php

namespace Extensions\venta\Controllers;

/**
 * Description templates for one Venta store. The shared behaviour lives in
 * App\Http\Controllers\Channels\DescriptionTemplateController; this class
 * exists so the permission catalogue keys the page on this channel
 * (venta/description_template) rather than on one cross-channel key.
 */
class VentaDescriptionTemplateController extends \App\Http\Controllers\Channels\DescriptionTemplateController
{
    protected function integration(): string
    {
        return 'venta';
    }
}
