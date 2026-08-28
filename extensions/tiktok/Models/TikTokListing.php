<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The product's TikTok record (#146, then parity #156): the settings an
 * individual push uses (category, brand, attribute answers, markups,
 * content overrides), the truth once pushed (tiktok_product_id and the
 * SKU map, written by every push whether from a group or from here),
 * the discount rule, and provenance. Group pivots keep per-group
 * attempt history and remain the fallback truth for products pushed
 * before this record carried it.
 */
class TikTokListing extends Model
{
    protected $table = 'tiktok_listings';

    protected $fillable = [
        'product_id',
        'tiktok_product_id', 'tiktok_sku_id',
        'tiktok_category_id', 'brand_id', 'brand_name', 'attribute_values',
        'title', 'description', 'markup_percent', 'markup_fixed',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'tiktok_activity_id',
        'last_pushed_at', 'last_push_source', 'last_checked_at',
        'live_status', 'live_checked_at',
    ];

    protected $casts = [
        'attribute_values' => 'array',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'live_checked_at' => 'datetime',
    ];

    /**
     * The mirrored bucket counts, keyed the way the listings strip names
     * them (TikTokLiveListing::TABS, minus all). Products on TikTok only:
     * the tab table shows nothing else.
     *
     * @param  array<string, array{0: string, 1: ?string}>  $tabs
     * @return array<string, int>
     */
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

    /** The listing's own price rule over the catalog price. */
    public function priceFor(float $corePrice): float
    {
        return round(
            $corePrice + ($corePrice * (float) ($this->markup_percent ?? 0) / 100) + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    /**
     * Write the truth a push produced. One product is one TikTok product,
     * so whichever surface pushed it (a group, the listing page) lands
     * the same fact here; the pivots keep their own attempt history.
     */
    public static function recordPush(int $productId, string $tiktokProductId, ?string $skuIds, string $source): void
    {
        static::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'tiktok_product_id' => $tiktokProductId,
                'tiktok_sku_id' => $skuIds,
                'last_pushed_at' => now(),
                'last_push_source' => $source,
            ]
        );
    }
}
