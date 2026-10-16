<?php

namespace Extensions\TikTok\Services\TikTok;

use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokProductGroupProduct;

/**
 * Before a push creates a TikTok Shop product, TikTok Shop is asked whether one already carries
 * this product's SKUs. A link can be lost while the product stays up, so the push never creates
 * on a missing link alone: it creates only when no TikTok Shop product holds the SKUs.
 */
final class TikTokCreateGuard
{
    public function __construct(private TikTokLiveListing $live, private TikTokLinkCheck $check)
    {
    }

    /**
     * @return array{verdict: 'existing'|'create'|'refuse', product_id: ?string, message: ?string}
     */
    public function check(array $c, int $productId, int $storeId): array
    {
        $skus = $this->check->skusByProduct([$productId])[$productId] ?? [];
        if ($skus === []) {
            return ['verdict' => 'create', 'product_id' => null, 'message' => null];
        }

        $found = $this->live->productsBySkus($c, $skus);
        if ($found['products'] === null) {
            return ['verdict' => 'refuse', 'product_id' => null, 'message' => 'TikTok Shop did not say whether it already holds this product (' . rtrim((string) $found['error'], '.') . '), so nothing was sent. Try again.'];
        }

        $hit = null;
        foreach ($found['products'] as $ttId => $p) {
            if (strtoupper($p['status']) === 'DELETED') {
                continue;
            }
            if (array_intersect(array_map('strtolower', $p['skus']), $skus) !== []) {
                $hit = (string) $ttId;
                break;
            }
        }
        if ($hit === null) {
            return ['verdict' => 'create', 'product_id' => null, 'message' => null];
        }

        $holder = $this->check->holder($hit, $productId, $storeId);
        if ($holder !== null) {
            return ['verdict' => 'refuse', 'product_id' => $hit, 'message' => "TikTok Shop product {$hit} already carries this product's SKU but is linked to Master Catalog product #{$holder}, so nothing was sent."];
        }

        return ['verdict' => 'existing', 'product_id' => $hit, 'message' => null];
    }

    /** Links the product to the TikTok Shop product that holds it, so the push updates it instead of listing it again. */
    public static function adopt(int $productId, string $tiktokProductId, ?TikTokProductGroupProduct $pivot = null): string
    {
        TikTokListing::query()->updateOrCreate(['product_id' => $productId], [
            'tiktok_product_id' => $tiktokProductId, 'tiktok_sku_id' => null,
            'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now(),
        ]);
        $pivot?->update(['tiktok_product_id' => $tiktokProductId, 'tiktok_sku_id' => null]);
        \App\Services\ActivityLogger::log(
            'updated', 'TikTok Listing', $productId,
            'Push found TikTok Shop product ' . $tiktokProductId . ' already holding this product, so it updated it instead of listing again.',
            ['reason' => 'the push asked TikTok Shop before creating', 'tiktok_product_id' => $tiktokProductId],
            'user'
        );

        return 'TikTok Shop already has this product as ' . $tiktokProductId . ', so it was updated, not listed again.';
    }
}
