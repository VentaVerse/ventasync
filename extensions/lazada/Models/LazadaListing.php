<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;
use Extensions\Lazada\Models\Concerns\BelongsToLazadaStore;

class LazadaListing extends Model
{
    use BelongsToLazadaStore, \App\Channels\Listings\KeepsItsOwnCopy;

    protected $table = 'ch_lazada_listings';

    protected $fillable = [
                'image_off', 'video_path', 'video_off',
'store_id', 'product_id', 'item_name', 'description',
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
        return $this->hasMany(LazadaListingAttribute::class, 'lazada_listing_id');
    }

    public function groups()
    {
        return $this->belongsToMany(LazadaProductGroup::class, 'ch_lazada_product_group_products');
    }

    public function variants()
    {
        return $this->hasMany(LazadaListingVariation::class, 'lazada_listing_id');
    }

    public function unlink(): void
    {
        $this->forceFill(['lazada_item_id' => null, 'unlinked_at' => now(), 'live_status' => null, 'live_checked_at' => null])->save();
        $this->variants()->delete();
        LazadaProductGroupProduct::query()->where('product_id', $this->product_id)
            ->whereIn('lazada_product_group_id', LazadaProductGroup::query()->withoutGlobalScope('lazadaStore')->where('store_id', $this->store_id)->select('id'))
            ->update(['sync_status' => 'unlinked']);
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
