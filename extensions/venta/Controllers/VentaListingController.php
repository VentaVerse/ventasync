<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaProductPush;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The per-product Venta surface (#171): one product on one store, the way
 * Shopee's and TikTok's listing pages show theirs. The live half is read
 * from the store on this load and written to the mirror; the record
 * beneath it (price rule, content on this store) is what the next push
 * from here sends. Saving never writes the catalogue product.
 *
 * Multi-store, like every Venta URL: the store is a path segment and the
 * listing record is one row per product per store.
 */
class VentaListingController extends Controller
{
    public function edit(int $store, int $productId)
    {
        $setting = VentaSetting::findOrFail($store);
        $push = VentaProductPush::for($setting);
        $product = $push->product($productId);
        abort_unless((bool) $product, 404);

        $listing = VentaListing::query()->where('venta_setting_id', $store)->where('product_id', $productId)->first();
        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();

        // The live half, read from the store on this load. A disabled store
        // is not asked: its answer would be about a shop nobody is selling
        // from, and the page still needs to render so the record can be
        // edited. The freshest answer there is goes to the mirror, so the
        // group pages agree with this page at once (#166).
        $live = null;
        $liveError = null;
        if ($setting->enabled && VentaProductPush::skuOf($product) !== '') {
            $live = $push->read($productId);
            if ($live['state'] === 'unreachable') {
                $liveError = $live['message'];
                $live = null;
            } else {
                $mirror = $listing ?? new VentaListing(['venta_setting_id' => $store, 'product_id' => $productId]);
                $mirror->mirror($live['status'], $live['price'], $live['quantity']);
                $listing = $mirror;

                // A product the store holds under our SKU with no link on
                // record is a link waiting to be made, and reconcile would
                // make it on the next push anyway. Made here so the page
                // can say "on the store" without a push.
                if ($live['state'] === 'found' && ! $link && $live['venta_id']) {
                    if ($push->linkProductTo($productId, $live['venta_id'], $live['sku']) === null) {
                        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();
                    }
                }
            }
        }

        $groups = VentaProductGroupProduct::query()
            ->join('venta_product_groups as g', 'g.id', '=', 'venta_product_group_products.venta_product_group_id')
            ->where('g.venta_setting_id', $store)
            ->where('venta_product_group_products.product_id', $productId)
            ->orderBy('g.name')
            ->get(['g.id', 'g.name', 'venta_product_group_products.sync_status', 'venta_product_group_products.last_pushed_at']);

        return view('ext-venta::listings.edit', [
            'setting' => $setting,
            'product' => $product,
            'sku' => VentaProductPush::skuOf($product),
            'listing' => $listing,
            'link' => $link,
            'live' => $live,
            'liveError' => $liveError,
            'groups' => $groups,
            'images' => $push->images($productId),
        ]);
    }

    public function update(Request $request, int $store, int $productId)
    {
        $setting = VentaSetting::findOrFail($store);
        abort_unless((bool) VentaProductPush::for($setting)->product($productId), 404);

        $data = $request->validate([
            'markup_percent' => 'nullable|numeric|min:-100|max:1000',
            'markup_fixed' => 'nullable|numeric|min:-1000000|max:1000000',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:20000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
        ]);

        // Content fields are OVERRIDES: an emptied field returns to null,
        // which means "follows the catalogue". Saving here never writes the
        // core product.
        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

        VentaListing::query()->updateOrCreate(
            ['venta_setting_id' => $store, 'product_id' => $productId],
            [
                'markup_percent' => $blank($data['markup_percent'] ?? null),
                'markup_fixed' => $blank($data['markup_fixed'] ?? null),
                'name' => $blank($data['name'] ?? null),
                'description' => $blank($data['description'] ?? null),
                'meta_title' => $blank($data['meta_title'] ?? null),
                'meta_description' => $blank($data['meta_description'] ?? null),
            ]
        );

        return redirect()->route('ext.venta.listings.edit', [$store, $productId])
            ->with('status', 'Listing saved. These settings drive the next push to ' . $setting->store_name . '.');
    }

    /**
     * Push this product to the store with the record's own content and
     * price rule: creates when the store does not have it, updates in
     * place when it does, and says which.
     */
    public function push(int $store, int $productId)
    {
        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was sent to it. Enable it in its settings first.');
        }

        $listing = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => $productId]);
        $push = VentaProductPush::for($setting);

        $outcome = $push->push(
            $productId,
            $listing->overrides(),
            $listing->hasPriceRule() ? fn (float $base) => $listing->priceFor($base) : null
        );

        if (! $outcome['ok']) {
            return redirect()->back()->with('error', 'Push failed: ' . $outcome['message']);
        }

        $payload = $outcome['payload'];
        $listing->forceFill([
            'last_pushed_at' => now(),
            'last_push_source' => 'listing',
            'last_push_settings' => [
                'state' => $outcome['state'],
                'price' => $payload['price'] ?? null,
                'markup_percent' => $listing->markup_percent,
                'markup_fixed' => $listing->markup_fixed,
                'overrides' => array_keys($listing->overrides()),
                'variants' => count($payload['variants'] ?? []),
                'images' => $outcome['images'],
            ],
        ])->save();
        $listing->mirror(
            ! empty($payload['status']) ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE,
            isset($payload['price']) ? (float) $payload['price'] : null,
            isset($payload['quantity']) ? (int) $payload['quantity'] : null
        );

        ActivityLogger::log('pushed', 'VentaListing', (int) $listing->id,
            ucfirst($outcome['state']) . ' product #' . $productId . ' on ' . $setting->store_name,
            ['venta_id' => $outcome['venta_id'], 'settings' => $listing->last_push_settings]);

        $sentence = $outcome['message'] . ' ' . VentaProductPush::imagesSentence($outcome['images']);
        $tone = ($outcome['images']['sent'] > 0 && $outcome['images']['stored'] < $outcome['images']['sent']) ? 'warning' : 'status';

        return redirect()->route('ext.venta.listings.edit', [$store, $productId])->with($tone, trim($sentence));
    }

    /** Unlist or relist on the store, in the store's own terms: active or not. */
    public function toggle(Request $request, int $store, int $productId)
    {
        $request->validate(['action' => 'required|in:unlist,relist']);
        $active = $request->input('action') === 'relist';

        $setting = VentaSetting::where('id', $store)->where('enabled', true)->first();
        if (! $setting) {
            return redirect()->back()->with('error', 'That Venta store is turned off, so nothing was changed on it.');
        }

        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();
        if (! $link || ! $link->venta_product_id) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: this product is not on ' . $setting->store_name . '.');
        }

        $result = VentaProductPush::for($setting)->setActive($productId, $active);
        if (! $result['ok']) {
            return redirect()->back()->with('error', ($active ? 'Relist' : 'Unlist') . ' failed: ' . $result['message']);
        }

        // Written through, so the next page view and every list agree
        // without waiting for a refresh (#166).
        $listing = VentaListing::query()->firstOrNew(['venta_setting_id' => $store, 'product_id' => $productId]);
        $listing->mirror($active ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE, $listing->live_price, $listing->live_quantity);

        ActivityLogger::log($active ? 'relisted' : 'unlisted', 'VentaListing', (int) $listing->id,
            ($active ? 'Relisted' : 'Unlisted') . ' product #' . $productId . ' on ' . $setting->store_name);

        return redirect()->back()->with('status', $result['message']);
    }
}
