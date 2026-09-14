<?php

namespace Extensions\lazada\Services;

use Extensions\lazada\Models\LazadaProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for "what ERP variants does this Lazada listing have,
 * and what quantity should each one carry on Lazada right now?".
 *
 * Both the UI flow (LazadaProductGroupController::pushStock / pushPrices) and
 * the cron flow (LazadaPushStock command) used to compute this independently
 * by reading product_option_value. Multi-option products (which store SKUs and
 * quantities in product_option_combinations rather than product_option_value)
 * silently fell through both paths, so cron-driven stock pushes never updated
 * Lazada for those products. That drift caused real overselling in production.
 *
 * This service consolidates the logic so future fixes only happen in one place.
 *
 *   buildPushItems(LazadaProduct $listing, object $product, string $pfx): array
 *     Returns [[ 'sku_id' => int, 'seller_sku' => string,
 *                'quantity' => int, 'price' => float|null ], …]
 *
 * Combination-based products take precedence; product_option_value rows are
 * used only when no combinations row exists for the product.
 */
class LazadaStockPushService
{
    /**
     * Build the per-Lazada-variant push payload for one listing.
     *
     * @param LazadaProduct $listing  with eager-loaded ->variants
     * @param object        $product  catalog product row (must have product_id, quantity, price, sku)
     * @param string        $pfx      catalog table prefix (config('catalog.prefix'))
     * @return array<int, array{sku_id:int, seller_sku:string, quantity:int, price:float|null}>
     */
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

        // 1) Combinations first — multi-option products live here. SKU column on
        //    combinations holds the seller_sku; absolute_price is the variant price.
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
            // 2) Single-option fallback: read directly from product_option_value.
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

        // A variation switched off for this store, or in the catalog, is not synced.
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

            // Match by seller_sku first (survives option-value renumbering),
            // then by product_option_value_id, then the base product as a last resort.
            $matched = true;
            if ($sellerSku !== '' && array_key_exists($sellerSku, $erpQtyBySku)) {
                $qty   = $erpQtyBySku[$sellerSku];
                $price = $erpPriceBySku[$sellerSku] ?? null;
            } elseif ($povId !== null && array_key_exists((int) $povId, $erpQtyByPov)) {
                $qty   = $erpQtyByPov[(int) $povId];
                $price = $erpPriceByPov[(int) $povId] ?? null;
            } elseif (!$hasErpVariants) {
                // Simple product (no ERP variants): catalog stock, and the
                // listing's own price when set, else the catalog's.
                $qty   = max(0, (int) ($product->quantity ?? 0));
                $price = \Extensions\lazada\Services\Lazada\LazadaPushPayload::startingPrice($listing, $basePrice);
            } else {
                // ERP has variants but this Lazada variant didn't match any of them.
                // Default to 0 stock so we don't oversell on the marketplace by
                // pushing a parent-row aggregate.
                Log::warning('LazadaStockPushService: unmatched Lazada variant — defaulting to 0', [
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
