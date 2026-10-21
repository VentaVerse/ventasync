<?php

namespace Extensions\VentaCart\Controllers;

use Extensions\VentaCart\Models\VentaCartProductGroup;
use Extensions\VentaCart\Models\VentaCartStore;

class VentaCartProductGroupController extends \App\Http\Controllers\Channels\ProductGroupController
{
    protected function channel(): string
    {
        return 'ventacart';
    }

    protected function groupModel(): string
    {
        return \Extensions\VentaCart\Models\VentaCartProductGroup::class;
    }

    protected function membership(): array
    {
        return ['ch_ventacart_product_group_products', 'ventacart_product_group_id'];
    }

    public function navRedirect()
    {
        $store = VentaCartStore::where('enabled', true)->first();
        if (!$store) {
            return redirect()->route('ext.ventacart.index')->with('error', 'No enabled VentaCart store. Add one first.');
        }
        return redirect()->route('ext.ventacart.product-groups.index', $store->id);
    }
}
