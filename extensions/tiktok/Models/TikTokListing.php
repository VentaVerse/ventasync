<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\tiktok\Models\Concerns\BelongsToTikTokStore;

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
    use BelongsToTikTokStore;

    protected $table = 'tiktok_listings';

    protected $fillable = [
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
        'image_order' => 'array',
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
     * The price the rule is added to: the listing's own Price when it has
     * one and the product has no variations, the catalog price otherwise.
     */
    public function startingPrice(float $corePrice, bool $hasVariations): float
    {
        return (! $hasVariations && (float) ($this->price ?? 0) > 0) ? (float) $this->price : $corePrice;
    }

    /**
     * The listings' own Price, by product, for those that have one. Only a
     * product without variations starts from it; the caller decides that.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
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

    /**
     * Write the truth a push produced. One product is one TikTok product,
     * so whichever surface pushed it (a group, the listing page) lands
     * the same fact here; the pivots keep their own attempt history.
     *
     * @param bool $answered false when $skuIds is the map already held, not one TikTok Shop answered with
     */
    public static function recordPush(int $productId, string $tiktokProductId, ?string $skuIds, string $source, bool $answered = true): void
    {
        $row = static::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'tiktok_product_id' => $tiktokProductId,
                'tiktok_sku_id' => $skuIds,
                'last_pushed_at' => now(),
                'last_push_source' => $source,
                // One latest error per product (the owner, 2026-09-05): a success clears it.
                'last_push_error' => null,
                'last_push_failed_at' => null,
            ]
        );

        // The map TikTok Shop just answered with is what the item holds.
        if ($answered && $skuIds !== null && $skuIds !== '') {
            \Extensions\tiktok\Services\TikTok\TikTokListingStates::rememberHeld(
                (int) ($row->tiktok_setting_id ?? 0),
                $productId,
                array_keys(\Extensions\tiktok\Services\TikTok\TikTokVariationPush::existingIds($skuIds))
            );
        }
    }

    /**
     * What this listing holds of its own (App\Integrations\Listings\ListingContent):
     * a blank column means "follow the catalog product".
     *
     * @return array{title: ?string, description: ?string}
     */
    public function contentOverrides(): array
    {
        return ['title' => $this->title, 'description' => $this->description];
    }

}
