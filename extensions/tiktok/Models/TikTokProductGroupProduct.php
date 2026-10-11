<?php

namespace Extensions\TikTok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroupProduct extends Model
{
    protected $table = 'ch_tiktok_product_group_products';

    public $timestamps = false;

    protected $fillable = [
        'tiktok_product_group_id', 'product_id', 'tiktok_product_id',
        'tiktok_sku_id', 'sync_status', 'last_pushed_at', 'push_error',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(TikTokProductGroup::class, 'tiktok_product_group_id');
    }

    public function scopeOnStore(\Illuminate\Database\Eloquent\Builder $query, TikTokStore|int|null $store = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn($query->qualifyColumn('tiktok_product_group_id'), self::storeGroups($store));
    }

    public static function groupIdsOn(TikTokStore|int|null $store = null): array
    {
        return self::storeGroups($store)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private static function storeGroups(TikTokStore|int|null $store): \Illuminate\Database\Query\Builder
    {
        $id = $store instanceof TikTokStore ? $store->id : ($store ?? TikTokStore::defaultStore()?->id);

        return \Illuminate\Support\Facades\DB::table('ch_tiktok_product_groups')->where('store_id', (int) $id)->select('id');
    }
}
