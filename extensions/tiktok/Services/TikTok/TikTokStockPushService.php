<?php

namespace Extensions\tiktok\Services\TikTok;

use Illuminate\Support\Facades\DB;

/**
 * "Given a TikTok pivot, what quantity and price does each of its SKUs
 * get right now?" The one resolver the cron (tiktok:push-stock) and the
 * group page's Push stock / Push price share, the way Shopee's and
 * Lazada's do. It reads product_option_combinations FIRST: the old paths
 * read product_option_value alone, which is exactly the drift that
 * oversold on Lazada before its resolver was extracted, and TikTok kept
 * that code until 2026-08-26.
 *
 * The pivot's tiktok_sku_id map is keyed by seller SKU from the same
 * date; legacy maps keyed by option_value_id are translated here, so a
 * product that was pushed before still gets the right figure until its
 * next push rewrites the map.
 */
class TikTokStockPushService
{
    /**
     * @param iterable  $pivots   TikTokProductGroupProduct rows (product_id, tiktok_product_id, tiktok_sku_id)
     * @param ?\Closure $priceFor float $base, object $pivot => float, or null when only stock is wanted
     * @return array<int, array{pivot: object, product_status: int, skus: array<int, array{id: string, seller_sku: string, quantity: int, price: float, matched: bool}>}>
     */
    public function resolve(iterable $pivots, string $pfx, ?\Closure $priceFor = null): array
    {
        $productIds = [];
        foreach ($pivots as $p) {
            if ((int) $p->product_id > 0) {
                $productIds[(int) $p->product_id] = true;
            }
        }
        $productIds = array_keys($productIds);
        if (!$productIds) {
            return [];
        }

        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'price', 'quantity', 'status'])
            ->keyBy('product_id');

        $combosBySku = DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'sku', 'quantity', 'absolute_price'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => trim((string) $r->sku)));

        $povs = DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'option_value_id', 'sku', 'quantity', 'absolute_price', 'price', 'price_prefix'])
            ->groupBy('product_id');
        $povByOvId = $povs->map(fn ($rows) => $rows->keyBy(fn ($r) => (string) $r->option_value_id));
        $povBySku = $povs->map(fn ($rows) => $rows->filter(fn ($r) => trim((string) $r->sku) !== '')->keyBy(fn ($r) => trim((string) $r->sku)));

        $priceFor ??= fn (float $base, object $pivot) => $base;
        $out = [];
        foreach ($pivots as $pivot) {
            $pid = (int) $pivot->product_id;
            $product = $products->get($pid);
            $basePrice = (float) ($product->price ?? 0);
            $map = TikTokVariationPush::existingIds($pivot->tiktok_sku_id ?? null);

            $skus = [];
            if (isset($map['__single__'])) {
                $skus[] = [
                    'id' => (string) $map['__single__'], 'seller_sku' => '',
                    'quantity' => max(0, (int) ($product->quantity ?? 0)),
                    'price' => max(0, $priceFor($basePrice, $pivot)), 'matched' => true,
                ];
            } else {
                foreach ($map as $key => $ttSkuId) {
                    if (!$ttSkuId) {
                        continue;
                    }
                    $key = (string) $key;
                    $qty = 0;
                    $base = $basePrice;
                    $matched = false;

                    $combo = $combosBySku->get($pid)?->get($key);
                    if ($combo !== null) {
                        $qty = (int) $combo->quantity;
                        $base = (float) $combo->absolute_price > 0 ? (float) $combo->absolute_price : $basePrice;
                        $matched = true;
                    } else {
                        // Legacy key: an option_value_id from a push before the
                        // seller-sku map. Its SKU may since have become a
                        // combination; try that before trusting the option row.
                        $pov = ctype_digit($key) ? $povByOvId->get($pid)?->get($key) : null;
                        $pov ??= $povBySku->get($pid)?->get($key);
                        if ($pov !== null) {
                            $legacyCombo = $combosBySku->get($pid)?->get(trim((string) $pov->sku));
                            if ($legacyCombo !== null) {
                                $qty = (int) $legacyCombo->quantity;
                                $base = (float) $legacyCombo->absolute_price > 0 ? (float) $legacyCombo->absolute_price : $basePrice;
                            } else {
                                $qty = (int) $pov->quantity;
                                $base = (float) ($pov->absolute_price ?? 0) > 0
                                    ? (float) $pov->absolute_price
                                    : ((($pov->price_prefix ?? '+') === '-') ? max(0, $basePrice - (float) $pov->price) : $basePrice + (float) $pov->price);
                            }
                            $matched = true;
                        }
                    }

                    $skus[] = [
                        'id' => (string) $ttSkuId, 'seller_sku' => $key,
                        'quantity' => max(0, $qty), 'price' => max(0, $priceFor($base, $pivot)), 'matched' => $matched,
                    ];
                }
            }

            $out[] = [
                'pivot' => $pivot,
                'product_status' => (int) ($product->status ?? 1),
                'skus' => $skus,
            ];
        }

        return $out;
    }
}
