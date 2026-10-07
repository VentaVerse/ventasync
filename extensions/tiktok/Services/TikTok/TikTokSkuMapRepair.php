<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;

class TikTokSkuMapRepair
{
    public function __construct(private readonly TikTokLiveListing $live)
    {
    }

    public function forProduct(array $c, int $productId, string $tiktokProductId): array
    {
        $answer = $this->live->fetch($tiktokProductId, $c);
        if ($answer['live'] === null) {
            return ['error' => (string) $answer['error']];
        }

        $map = [];
        foreach ((array) ($answer['live']['skus'] ?? []) as $sku) {
            $sellerSku = trim((string) ($sku['seller_sku'] ?? ''));
            $id = (string) ($sku['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($sellerSku !== '') {
                $map[$sellerSku] = $id;
            } elseif ($map === [] && count($answer['live']['skus']) === 1) {
                $map['__single__'] = $id;
            }
        }

        if ($map === []) {
            return ['error' => 'TikTok Shop returned no SKUs for product ' . $tiktokProductId . '. Nothing was changed.'];
        }

        $encoded = json_encode($map);
        TikTokListing::query()->where('product_id', $productId)
            ->update(['tiktok_sku_id' => $encoded]);
        TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)->where('product_id', $productId)
            ->whereNotNull('tiktok_product_id')
            ->update(['tiktok_sku_id' => $encoded]);
        TikTokListingStates::rememberHeld(
            is_object($c['setting'] ?? null) ? (int) $c['setting']->id : (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0),
            $productId,
            array_keys($map)
        );

        return ['mapped' => count($map), 'map' => $map];
    }
}
