<?php

namespace Extensions\Lazada\Models\Concerns;

use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToLazadaStore
{
    public static function bootBelongsToLazadaStore(): void
    {
        static::creating(function ($model) {
            $model->store_id ??= LazadaStore::defaultStore()?->id;
        });

        static::addGlobalScope('lazadaStore', function (Builder $query) {
            if (app()->bound('lazada.route-store')) {
                $query->where($query->qualifyColumn('store_id'), app('lazada.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(LazadaStore::class, 'store_id');
    }

    public function scopeForStore(Builder $query, LazadaStore|int|null $store): Builder
    {
        $id = $store instanceof LazadaStore ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('store_id'), $id);
    }
}
