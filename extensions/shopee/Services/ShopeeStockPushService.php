<?php

namespace Extensions\Shopee\Services;

use Illuminate\Support\Facades\DB;

class ShopeeStockPushService
{
    public function resolve(iterable $links): array
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

        $products = DB::table('products')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'price', 'quantity', 'status'])
            ->keyBy('product_id');

        $comboBySku = DB::table('product_variations')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'absolute_price'])
            ->groupBy('product_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($r) => trim((string) $r->sku));
            });

        $povBySku = DB::table('product_variation_values')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'absolute_price'])
            ->groupBy('product_id')
            ->map(function ($rows) {
                return $rows->keyBy(fn ($r) => trim((string) $r->sku));
            });

        $own = [];
        foreach (DB::table(\App\Channels\Listings\VariationPrices::TABLE)->where('channel', 'shopee')
            ->whereIn('product_id', $productIds)->get(['store_id', 'product_id', 'sku', 'price']) as $r) {
            $own[(int) $r->store_id][(int) $r->product_id][$r->sku] = (float) $r->price;
        }

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
                if ($matched) {
                    $price = \App\Channels\Listings\VariationPrices::base($own[(int) ($link->store_id ?? 0)] ?? [], $pid, $sku, $price);
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
