<?php

namespace Extensions\shopee\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One product's Shopee listing: its settings, its Shopee-specific content,
 * and the provenance of its last push. See the shopee_listings migration
 * for the contract (card #97) and what is deliberately NOT stored here.
 */
class ShopeeListing extends Model
{
    use \Extensions\shopee\Models\Concerns\BelongsToShopeeStore;

    protected $table = 'shopee_listings';

    protected $fillable = [
        'shopee_setting_id',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'product_id',
        'shopee_category_id', 'shopee_brand_id', 'logistic_ids',
        'markup_percent', 'markup_fixed',
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
        'image_order' => 'array',
        'last_push_settings' => 'array',
        'last_pushed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'last_push_failed_at' => 'datetime',
    ];

    /** The price this listing sells at, from the core price it never stores. */
    public function priceFor(float $corePrice): float
    {
        return round(
            $corePrice
            + ($corePrice * (float) ($this->markup_percent ?? 0) / 100)
            + (float) ($this->markup_fixed ?? 0),
            2
        );
    }

    /** Whether an unattended push has what it needs. */
    public function pushable(): bool
    {
        return $this->shopee_category_id !== null && ! empty($this->logistic_ids);
    }

    /**
     * What this listing holds of its own (App\Integrations\Listings\ListingContent):
     * a blank column means "follow the catalog product".
     *
     * @return array{title: ?string, description: ?string}
     */
    public function contentOverrides(): array
    {
        return ['title' => $this->item_name, 'description' => $this->description];
    }

}
