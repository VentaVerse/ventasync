<?php

namespace Extensions\lazada\Services;

use Extensions\lazada\Models\LazadaProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Combination rows take precedence; product_option_value is used only when none exist.
class LazadaStockPushService
{
    public function buildPushItems(LazadaProduct $listing, object $product, string $pfx): array
    {
        $lazadaVariants = $listing->variants;
        if ($lazadaVariants->isEmpty()) {
            return [];
        }

        $productId = (int) $product->product_id;
        $basePrice = (float) ($product->price ?? 0);

        $erpQtyBySku   = [];
        $erpPriceBySku = [];
        $erpQtyByPov   = [];
        $erpPriceByPov = [];

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->get(['id', 'sku', 'quantity', 'absolute_price']);

        if ($combos->isNotEmpty()) {
            foreach ($combos as $c) {
                $sku = trim((string) ($c->sku ?? ''));
                if ($sku !== '') {
                    $erpQtyBySku[$sku]   = max(0, (int) ($c->quantity ?? 0));
                    $erpPriceBySku[$sku] = (float) ($c->absolute_price ?? $basePrice);
                }
            }
        } else {
            $povRows = DB::table($pfx . 'product_option_value')
                ->where('product_id', $productId)
                ->get(['product_option_value_id', 'sku', 'quantity', 'absolute_price']);

            foreach ($povRows as $row) {
                $pov = (int) $row->product_option_value_id;
                $sku = trim((string) ($row->sku ?? ''));
                $erpQtyByPov[$pov]   = max(0, (int) ($row->quantity ?? 0));
                $erpPriceByPov[$pov] = (float) ($row->absolute_price ?? $basePrice);
                if ($sku !== '') {
                    $erpQtyBySku[$sku]   = max(0, (int) ($row->quantity ?? 0));
                    $erpPriceBySku[$sku] = (float) ($row->absolute_price ?? $basePrice);
                }
            }
        }

        $hasErpVariants = !empty($erpQtyBySku) || !empty($erpQtyByPov);

        $hidden = \App\Integrations\Listings\ListingVariations::hidden('lazada', (int) ($listing->lazada_setting_id ?? 0), [$productId]);

        $items = [];
        foreach ($lazadaVariants as $variant) {
            $skuId = $variant->sku_id ?? null;
            if (!$skuId) {
                continue;
            }
            $sellerSku = trim((string) ($variant->seller_sku ?? ''));
            if (!\App\Integrations\Listings\ListingVariations::allows($hidden, $productId, $sellerSku)) {
                continue;
            }
            $povId     = $variant->product_option_value_id;

            $matched = true;
            if ($sellerSku !== '' && array_key_exists($sellerSku, $erpQtyBySku)) {
                $qty   = $erpQtyBySku[$sellerSku];
                $price = $erpPriceBySku[$sellerSku] ?? null;
            } elseif ($povId !== null && array_key_exists((int) $povId, $erpQtyByPov)) {
                $qty   = $erpQtyByPov[(int) $povId];
                $price = $erpPriceByPov[(int) $povId] ?? null;
            } elseif (!$hasErpVariants) {
                $qty   = max(0, (int) ($product->quantity ?? 0));
                $price = \Extensions\lazada\Services\Lazada\LazadaPushPayload::startingPrice($listing, $basePrice);
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
