<?php

namespace Extensions\Shopee\Models\Concerns;

use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToShopeeStore
{
    public static function bootBelongsToShopeeStore(): void
    {
        static::creating(function ($model) {
            $model->store_id ??= ShopeeStore::defaultStore()?->id;
        });

        static::addGlobalScope('shopeeStore', function (Builder $query) {
            if (app()->bound('shopee.route-store')) {
                $query->where($query->qualifyColumn('store_id'), app('shopee.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopeeStore::class, 'store_id');
    }

    public function scopeForStore(Builder $query, ShopeeStore|int|null $store): Builder
    {
        $id = $store instanceof ShopeeStore ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('store_id'), $id);
    }
}
