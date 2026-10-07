<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\lazada\Models\Concerns\BelongsToLazadaStore;

class LazadaProduct extends Model
{
    use BelongsToLazadaStore, \App\Integrations\Listings\KeepsItsOwnCopy;

    protected $table = 'lazada_products';

    protected $fillable = [
                'image_off', 'video_path', 'video_off',
'lazada_setting_id', 'product_id', 'item_name', 'description',
        'description_prefix_id', 'description_suffix_id', 'watermark_template_id', 'watermark_all_images',
        'primary_category_id', 'brand_id', 'brand_name_override', 'image_order',
        'markup_fixed', 'markup_percent',
        'price', 'weight', 'package_length', 'package_width', 'package_height',
        'lazada_item_id', 'unlinked_at', 'live_status', 'live_checked_at',
        'last_pushed_at', 'last_push_source', 'last_push_settings',
        'last_push_error', 'last_push_failed_at',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'sale_pushed_at',
    ];

    protected $casts = [
        'watermark_all_images' => 'boolean',
        'price' => 'float',
        'weight' => 'float',
        'package_length' => 'integer',
        'package_width' => 'integer',
        'package_height' => 'integer',
        'unlinked_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_failed_at' => 'datetime',
        'last_push_settings' => 'array',
        'image_order' => 'array', 'image_off' => 'array',
        'catalog_seen_at' => 'datetime',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'sale_pushed_at' => 'datetime',
    ];

    public static function mirrorCounts(array $tabMap): array
    {
        $byStatus = static::query()
            ->whereNotNull('live_status')
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->selectRaw('live_status, COUNT(DISTINCT product_id) as n')
            ->groupBy('live_status')
            ->pluck('n', 'live_status');

        $out = [];
        foreach ($tabMap as $tabKey => $status) {
            $out[$tabKey] = (int) ($byStatus[$status] ?? 0);
        }

        return $out;
    }

    public function attributes()
    {
        return $this->hasMany(LazadaProductAttribute::class, 'lazada_product_id');
    }

    public function groups()
    {
        return $this->belongsToMany(LazadaProductGroup::class, 'lazada_product_group_products');
    }

    public function variants()
    {
        return $this->hasMany(LazadaProductVariant::class, 'lazada_product_id');
    }

    public function contentOverrides(): array
    {
        return ['title' => $this->item_name, 'description' => $this->description];
    }

    public static function copyColumns(): array
    {
        return ['title' => 'item_name', 'description' => 'description', 'images' => 'image_order', 'plain' => false];
    }

}
