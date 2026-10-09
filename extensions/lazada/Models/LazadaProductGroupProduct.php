<?php

namespace Extensions\Lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroupProduct extends Model
{
    protected $table = 'ch_lazada_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'lazada_product_group_id', 'lazada_listing_id', 'product_id',
        'sync_status', 'last_pushed_at', 'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(LazadaProductGroup::class, 'lazada_product_group_id');
    }

    public function lazadaProduct()
    {
        return $this->belongsTo(LazadaListing::class, 'lazada_listing_id');
    }

    public function scopeOnStore(\Illuminate\Database\Eloquent\Builder $query, LazadaStore|int|null $store = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn($query->qualifyColumn('lazada_product_group_id'), self::storeGroups($store));
    }

    public static function groupIdsOn(LazadaStore|int|null $store = null): array
    {
        return self::storeGroups($store)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private static function storeGroups(LazadaStore|int|null $store): \Illuminate\Database\Query\Builder
    {
        $id = $store instanceof LazadaStore ? $store->id : ($store ?? LazadaStore::defaultStore()?->id);

        return \Illuminate\Support\Facades\DB::table('ch_lazada_product_groups')->where('store_id', (int) $id)->select('id');
    }
}
