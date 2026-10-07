<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeListing extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore, \App\Integrations\Listings\KeepsItsOwnCopy;

    protected $table = 'shopee_listings';

    protected $fillable = [
                'image_off', 'video_path', 'video_off',
'shopee_setting_id',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'product_id',
        'shopee_category_id', 'shopee_brand_id', 'logistic_ids',
        'markup_percent', 'markup_fixed', 'price',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'shopee_discount_id',
        'item_name', 'description', 'attribute_values', 'image_order', 'weight',
        'package_length', 'package_width', 'package_height',
        'last_pushed_at', 'last_push_source', 'last_push_settings', 'last_checked_at',
        'last_push_error', 'last_push_failed_at',
    ];

    protected $casts = [
        'watermark_all_images' => 'boolean',
        'logistic_ids' => 'array',
        'attribute_values' => 'array',
        'image_order' => 'array', 'image_off' => 'array',
        'last_push_settings' => 'array',
        'last_pushed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'price' => 'float',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'last_push_failed_at' => 'datetime',
        'catalog_seen_at' => 'datetime',
    ];

    public function priceFor(float $corePrice): float
    {
        return round(
            $corePrice
            + ($corePrice * (float) ($this->markup_percent ?? 0) / 100)
            + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    public function startingPrice(float $corePrice, bool $hasVariations): float
    {
        $own = $this->price !== null ? (float) $this->price : 0.0;

        return (! $hasVariations && $own > 0) ? $own : $corePrice;
    }

    public function itemPriceFor(float $corePrice, bool $hasVariations): float
    {
        return $this->priceFor($this->startingPrice($corePrice, $hasVariations));
    }

    public static function withVariations(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $set = array_fill_keys(array_map('intval', array_keys(
            \App\Integrations\Push\PushLedger::erpVariationSkus($productIds, (string) config('catalog.prefix'))
        )), true);
        foreach (\Illuminate\Support\Facades\DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds)->distinct()->pluck('product_id') as $pid) {
            $set[(int) $pid] = true;
        }

        return $set;
    }

    public static function hasVariations(int $productId): bool
    {
        return isset(self::withVariations([$productId])[$productId]);
    }

    public function pushable(): bool
    {
        return $this->shopee_category_id !== null && ! empty($this->logistic_ids);
    }

    public function contentOverrides(): array
    {
        return ['title' => $this->item_name, 'description' => $this->description];
    }

    public static function copyColumns(): array
    {
        return ['title' => 'item_name', 'description' => 'description', 'images' => 'image_order', 'plain' => true];
    }

}
