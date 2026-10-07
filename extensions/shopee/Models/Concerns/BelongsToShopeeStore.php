<?php

namespace Extensions\shopee\Models\Concerns;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToShopeeStore
{
    public static function bootBelongsToShopeeStore(): void
    {
        static::creating(function ($model) {
            $model->shopee_setting_id ??= ShopeeSetting::defaultStore()?->id;
        });

        static::addGlobalScope('shopeeStore', function (Builder $query) {
            if (app()->bound('shopee.route-store')) {
                $query->where($query->qualifyColumn('shopee_setting_id'), app('shopee.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopeeSetting::class, 'shopee_setting_id');
    }

    public function scopeForStore(Builder $query, ShopeeSetting|int|null $store): Builder
    {
        $id = $store instanceof ShopeeSetting ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('shopee_setting_id'), $id);
    }
}
