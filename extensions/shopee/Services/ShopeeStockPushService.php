<?php

namespace Extensions\shopee\Services;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "given a Shopee product link, what quantity and
 * price should I push to Shopee right now?".
 *
 * Both the UI flow (ShopeeProductGroupController::pushStockForLinks /
 * pushPriceForLinks) and the cron flow (ShopeePushStock command) used to
 * compute this independently by reading product_option_value. Multi-option
 * products store SKUs and quantities in product_option_combinations rather
 * than product_option_value, so they silently fell through both paths —
 * exactly the drift that caused production overselling on Lazada before
 * we extracted LazadaStockPushService. Mirroring that pattern here so the
 * Shopee cron and UI consume one resolver.
 *
 * For each link, this resolver:
 *   1. Tries to match link->sku against product_option_combinations.sku
 *      first (multi-option products).
 *   2. Falls back to product_option_value (single-option products).
 *   3. Falls back to the base product (no ERP variants).
 *
 * Returns an associative row per link: model_id, seller_sku, quantity, price.
 * The caller groups by shopee_item_id and assembles the Shopee API payload.
 */
class ShopeeStockPushService
{
    /**
     * @param iterable $links collection / array of ShopeeProductLink objects
     *                        (must expose product_id, sku, shopee_item_id, shopee_model_id)
     * @param string $pfx     catalog table prefix
     * @return array<int, array{
     *     link: object,
     *     item_id: int,
     *     model_id: int,
     *     seller_sku: string,
     *     quantity: int,
     *     price: float,
     *     matched: bool
     * }>
     */
    public function resolve(iterable $links, string $pfx): array
    {
        $productIds = [];
        foreach ($links as $l) {
            $pid = (int) ($l->product_id ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = true;
            }
        }
        $productIds = array_keys($productIds);
        if (empty($productIds)) {
            return [];
        }

        // 1) Product base rows.
        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'price', 'quantity', 'status'])
            ->keyBy('product_id');

        // 2) Combinations (multi-option products) — keyed by (product_id, sku).
        $comboBySku = DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'absolute_price'])
            ->groupBy('product_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($r) => trim((string) $r->sku));
            });

        // 3) POV (single-option products) — keyed by (product_id, sku).
        $povBySku = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'absolute_price'])
            ->groupBy('product_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($r) => trim((string) $r->sku));
            });

        $out = [];
        foreach ($links as $link) {
            $pid = (int) ($link->product_id ?? 0);
            $sku = trim((string) ($link->sku ?? ''));
            $modelId = (int) ($link->shopee_model_id ?? 0);
            $itemId = (int) ($link->shopee_item_id ?? 0);

            $product = $pid > 0 ? ($products->get($pid) ?? null) : null;
            $basePrice = (float) ($product->price ?? 0);
            $baseQty = max(0, (int) ($product->quantity ?? 0));

            $qty = $baseQty;
            $price = $basePrice;
            $matched = false;

            if ($sku !== '' && $pid > 0) {
                $comboRow = $comboBySku->get($pid)?->get($sku) ?? null;
                if ($comboRow !== null) {
                    $qty = max(0, (int) ($comboRow->quantity ?? 0));
                    $price = (float) ($comboRow->absolute_price ?? $basePrice);
                    $matched = true;
                } else {
                    $povRow = $povBySku->get($pid)?->get($sku) ?? null;
                    if ($povRow !== null) {
                        $qty = max(0, (int) ($povRow->quantity ?? 0));
                        if ($povRow->absolute_price !== null) {
                            $price = max(0, (float) $povRow->absolute_price);
                        }
                        $matched = true;
                    }
                }
            }

            $out[] = [
                'link'       => $link,
                'item_id'    => $itemId,
                'model_id'   => $modelId,
                'seller_sku' => $sku,
                'quantity'   => $qty,
                'price'      => max(0, $price),
                'matched'    => $matched,
                'product_status' => (int) ($product->status ?? 1),
            ];
        }

        return $out;
    }
}
