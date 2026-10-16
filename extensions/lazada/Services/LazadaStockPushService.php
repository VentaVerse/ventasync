<?php

namespace Extensions\Lazada\Services;

use Extensions\Lazada\Models\LazadaListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Combination rows take precedence; product_option_value is used only when none exist.
class LazadaStockPushService
{
    public function buildPushItems(LazadaListing $listing, object $product): array
    {
        $lazadaVariants = $listing->variants;
        if ($lazadaVariants->isEmpty()) {
            return [];
        }

        $productId = (int) $product->product_id;
        $basePrice = (float) ($product->price ?? 0);

        $catalogQtyBySku   = [];
        $catalogPriceBySku = [];
        $catalogQtyByPov   = [];
        $catalogPriceByPov = [];

        $combos = DB::table('product_variations')
            ->where('product_id', $productId)
            ->get(['id', 'sku', 'quantity', 'absolute_price']);

        if ($combos->isNotEmpty()) {
            foreach ($combos as $c) {
                $sku = trim((string) ($c->sku ?? ''));
                if ($sku !== '') {
                    $catalogQtyBySku[$sku]   = max(0, (int) ($c->quantity ?? 0));
                    $catalogPriceBySku[$sku] = (float) ($c->absolute_price ?? $basePrice);
                }
            }
        } else {
            $povRows = DB::table('product_variation_values')
                ->where('product_id', $productId)
                ->get(['product_variation_value_id', 'sku', 'quantity', 'absolute_price']);

            foreach ($povRows as $row) {
                $pov = (int) $row->product_variation_value_id;
                $sku = trim((string) ($row->sku ?? ''));
                $catalogQtyByPov[$pov]   = max(0, (int) ($row->quantity ?? 0));
                $catalogPriceByPov[$pov] = (float) ($row->absolute_price ?? $basePrice);
                if ($sku !== '') {
                    $catalogQtyBySku[$sku]   = max(0, (int) ($row->quantity ?? 0));
                    $catalogPriceBySku[$sku] = (float) ($row->absolute_price ?? $basePrice);
                }
            }
        }

        $hasCatalogVariations = !empty($catalogQtyBySku) || !empty($catalogQtyByPov);

        $hidden = \App\Channels\Listings\ListingVariations::hidden('lazada', (int) ($listing->store_id ?? 0), [$productId]);

        $items = [];
        foreach ($lazadaVariants as $variant) {
            $skuId = $variant->sku_id ?? null;
            if (!$skuId) {
                continue;
            }
            $sellerSku = trim((string) ($variant->seller_sku ?? ''));
            if (!\App\Channels\Listings\ListingVariations::allows($hidden, $productId, $sellerSku)) {
                continue;
            }
            $povId     = $variant->product_variation_value_id;

            $matched = true;
            if ($sellerSku !== '' && array_key_exists($sellerSku, $catalogQtyBySku)) {
                $qty   = $catalogQtyBySku[$sellerSku];
                $price = $catalogPriceBySku[$sellerSku] ?? null;
            } elseif ($povId !== null && array_key_exists((int) $povId, $catalogQtyByPov)) {
                $qty   = $catalogQtyByPov[(int) $povId];
                $price = $catalogPriceByPov[(int) $povId] ?? null;
            } elseif (!$hasCatalogVariations) {
                $qty   = max(0, (int) ($product->quantity ?? 0));
                $price = \Extensions\Lazada\Services\Lazada\LazadaPushPayload::startingPrice($listing, $basePrice);
            } else {
                Log::warning('LazadaStockPushService: unmatched Lazada variant, defaulting to 0', [
                    'product_id'        => $productId,
                    'lazada_seller_sku' => $sellerSku,
                    'lazada_sku_id'     => $skuId,
                ]);
                $qty   = 0;
                $price = null;
                $matched = false;
            }

            $items[] = [
                'sku_id'     => (int) $skuId,
                'seller_sku' => $sellerSku,
                'matched'    => $matched,
                'quantity'   => (int) $qty,
                'price'      => $price !== null ? (float) $price : null,
            ];
        }

        return $items;
    }
}
