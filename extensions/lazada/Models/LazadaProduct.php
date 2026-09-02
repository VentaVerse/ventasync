<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProduct extends Model
{
    // Lazada products table
    protected $table = 'lazada_products';

    protected $fillable = [
        'product_id', 'primary_category_id', 'brand_id', 'brand_name_override',
        // The markups were missing here, and every create() that passed them
        // dropped them SILENTLY - the same disease 0af64cea documented on
        // the group dance's update(). Found again by #195's save-as pin.
        'markup_fixed', 'markup_percent',
        'total_price', 'lazada_item_id', 'unlinked_at', 'live_status', 'live_checked_at',
        'last_pushed_at', 'last_push_source', 'last_push_settings',
        'sale_percent', 'sale_starts_at', 'sale_ends_at', 'sale_pushed_at',
    ];

    protected $casts = [
        'unlinked_at' => 'datetime',
        'live_checked_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'last_push_settings' => 'array',
        'sale_percent' => 'float',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'sale_pushed_at' => 'datetime',
    ];

    /**
     * The mirrored bucket counts, keyed the way the listings strip names
     * them. Uploaded listings only: the tab table shows nothing else, so
     * the count must not promise more rows than the tab has.
     *
     * @param  array<string, string>  $tabMap  tab key => Lazada status word
     * @return array<string, int>
     */
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
}
