<?php

namespace Extensions\tiktok\Models;

use Illuminate\Database\Eloquent\Model;

class TikTokProductGroupProduct extends Model
{
    protected $table = 'tiktok_product_group_products';

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

    public function scopeOnStore(\Illuminate\Database\Eloquent\Builder $query, TikTokSetting|int|null $store = null): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn($query->qualifyColumn('tiktok_product_group_id'), self::storeGroups($store));
    }

    public static function groupIdsOn(TikTokSetting|int|null $store = null): array
    {
        return self::storeGroups($store)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private static function storeGroups(TikTokSetting|int|null $store): \Illuminate\Database\Query\Builder
    {
        $id = $store instanceof TikTokSetting ? $store->id : ($store ?? TikTokSetting::defaultStore()?->id);

        return \Illuminate\Support\Facades\DB::table('tiktok_product_groups')->where('tiktok_setting_id', (int) $id)->select('id');
    }
}
