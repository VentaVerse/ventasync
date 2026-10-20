<?php

namespace Extensions\VentaCart\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\VentaCart\Models\VentaCartBrand;
use Extensions\VentaCart\Models\VentaCartCategory;
use Extensions\VentaCart\Models\VentaCartProductGroup;
use Extensions\VentaCart\Models\VentaCartProductGroupProduct;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Services\VentaCart\VentaCartClient;
use Extensions\VentaCart\Services\VentaCart\VentaCartProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    private function store_(int $storeId): VentaCartStore
    {
        return VentaCartStore::findOrFail($storeId);
    }

    private function productsRedirect(int $store, int $group)
    {
        $fallback = route('ext.ventacart.listings.index', ['store' => $store, 'group' => $group]);

        return redirect(\App\Support\BackTo::safe(request()->input('_return'), $fallback));
    }

    public function moveProducts(Request $request, int $store)
    {
        $this->store_($store);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])), fn ($v) => $v > 0)));
        $back = \App\Support\BackTo::safe($request->input('_return'), route('ext.ventacart.listings.index', $store));
        if ($ids === []) {
            return redirect($back)->with('warning', 'No products selected.');
        }
        $target = (string) $request->input('group', '');
        if ($target === 'none') {
            $removed = VentaCartProductGroupProduct::query()
                ->whereIn('ventacart_product_group_id', VentaCartProductGroup::where('store_id', $store)->select('id'))
                ->whereIn('product_id', $ids)->delete();

            return redirect($back)->with('status', $removed . ' ' . \Illuminate\Support\Str::plural('product', $removed) . ' ungrouped.');
        }
        $group = VentaCartProductGroup::where('store_id', $store)->findOrFail((int) $target);

        $already = $group->products()->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $wanted = array_values(array_diff($ids, $already));
        $claim = \App\Channels\OneGroupRule::claim('ch_ventacart_product_group_products', 'ventacart_product_group_id', 'ch_ventacart_product_groups', 'store_id', $group->id, $wanted, $wanted);
        $moved = 0;
        foreach (array_merge($claim['free'], array_keys($claim['moved'])) as $pid) {
            VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => (int) $pid]);
            $moved++;
        }
        if ($moved === 0) {
            return redirect($back)->with('warning', 'Those products are already in ' . $group->name . '.');
        }

        return redirect($back)->with('status', $moved . ' ' . \Illuminate\Support\Str::plural('product', $moved) . ' moved to ' . $group->name . '.');
    }

    public function massRemove(Request $request, int $store, int $group)
    {
        $this->store_($store);
        $g = VentaCartProductGroup::where('store_id', $store)->findOrFail($group);

        $ids = array_map('intval', array_filter((array) $request->input('ids', [])));

        if (!empty($ids)) {
            $g->products()->whereIn('product_id', $ids)->delete();
            \Extensions\VentaCart\Services\VentaCart\VentaCartListingStates::for($store)->clearErrors($ids);
        }

        return $this->productsRedirect($store, $group)
            ->with('status', count($ids) . ' product(s) removed from the product group.');
    }
}
