<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\tiktok\Models\Concerns\BelongsToTikTokStore;

class TikTokListing extends Model
{
    use BelongsToTikTokStore, \App\Integrations\Listings\KeepsItsOwnCopy;

    protected $table = 'tiktok_listings';

    protected $fillable = [
                'image_off', 'video_path', 'video_off',
'tiktok_setting_id', 'product_id',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'tiktok_product_id', 'tiktok_sku_id',
        'tiktok_category_id', 'brand_id', 'brand_name', 'attribute_values', 'image_order',
        'title', 'description', 'markup_percent', 'markup_fixed',
        'price', 'weight', 'package_length', 'package_width', 'package_height',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'tiktok_activity_id',
        'last_pushed_at', 'last_push_source', 'last_checked_at',
        'last_push_error', 'last_push_failed_at',
        'live_status', 'live_checked_at',
    ];

    protected $casts = [
        'watermark_all_images' => 'boolean',
        'attribute_values' => 'array',
        'image_order' => 'array', 'image_off' => 'array',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'price' => 'float',
        'weight' => 'float',
        'package_length' => 'integer',
        'package_width' => 'integer',
        'package_height' => 'integer',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_failed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'catalog_seen_at' => 'datetime',
    ];

    public static function mirrorCounts(array $tabs): array
    {
        $byStatus = static::query()
            ->whereNotNull('tiktok_product_id')
            ->whereNotNull('live_status')
            ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach ($tabs as $key => [$label, $status]) {
            if ($status !== null) {
                $out[$key] = (int) ($byStatus[$status] ?? 0);
            }
        }

        return $out;
    }

    public function priceFor(float $corePrice): float
    {
        return round(
            $corePrice + ($corePrice * (float) ($this->markup_percent ?? 0) / 100) + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    public function startingPrice(float $corePrice, bool $hasVariations): float
    {
        return (! $hasVariations && (float) ($this->price ?? 0) > 0) ? (float) $this->price : $corePrice;
    }

    public static function ownPrices(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        return static::query()->whereIn('product_id', $productIds)->where('price', '>', 0)
            ->pluck('price', 'product_id')
            ->mapWithKeys(fn ($price, $pid) => [(int) $pid => (float) $price])
            ->all();
    }

    public static function recordPush(int $productId, string $tiktokProductId, ?string $skuIds, string $source, bool $answered = true): void
    {
        $row = static::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'tiktok_product_id' => $tiktokProductId,
                'tiktok_sku_id' => $skuIds,
                'last_pushed_at' => now(),
                'last_push_source' => $source,
                'last_push_error' => null,
                'last_push_failed_at' => null,
            ]
        );

        if ($answered && $skuIds !== null && $skuIds !== '') {
            \Extensions\tiktok\Services\TikTok\TikTokListingStates::rememberHeld(
                (int) ($row->tiktok_setting_id ?? 0),
                $productId,
                array_keys(\Extensions\tiktok\Services\TikTok\TikTokVariationPush::existingIds($skuIds))
            );
        }
    }

    public function contentOverrides(): array
    {
        return ['title' => $this->title, 'description' => $this->description];
    }

    public static function copyColumns(): array
    {
        return ['title' => 'title', 'description' => 'description', 'images' => 'image_order', 'plain' => false];
    }

}
