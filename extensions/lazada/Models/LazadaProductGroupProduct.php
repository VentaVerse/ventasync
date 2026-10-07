<?php

namespace Extensions\lazada\Models;

use Illuminate\Database\Eloquent\Model;

class LazadaProductGroupProduct extends Model
{
    protected $table = 'lazada_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'lazada_product_group_id', 'lazada_product_id', 'product_id',
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
        return $this->belongsTo(LazadaProduct::class, 'lazada_product_id');
    }

    public function scopeOnStore(\Illuminate\Database\Eloquent\Builder $query, LazadaSetting|int|null $store = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn($query->qualifyColumn('lazada_product_group_id'), self::storeGroups($store));
    }

    public static function groupIdsOn(LazadaSetting|int|null $store = null): array
    {
        return self::storeGroups($store)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private static function storeGroups(LazadaSetting|int|null $store): \Illuminate\Database\Query\Builder
    {
        $id = $store instanceof LazadaSetting ? $store->id : ($store ?? LazadaSetting::defaultStore()?->id);

        return \Illuminate\Support\Facades\DB::table('lazada_product_groups')->where('lazada_setting_id', (int) $id)->select('id');
    }
}
