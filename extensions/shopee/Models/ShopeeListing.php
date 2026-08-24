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
    protected $table = 'shopee_listings';

    protected $fillable = [
        'product_id',
        'shopee_category_id', 'shopee_brand_id', 'logistic_ids',
        'markup_percent', 'markup_fixed',
        'item_name', 'description', 'attribute_values', 'weight',
        'package_length', 'package_width', 'package_height',
        'last_pushed_at', 'last_push_source', 'last_push_settings', 'last_checked_at',
    ];

    protected $casts = [
        'logistic_ids' => 'array',
        'attribute_values' => 'array',
        'last_push_settings' => 'array',
        'last_pushed_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'markup_percent' => 'float',
        'markup_fixed' => 'float',
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
}
