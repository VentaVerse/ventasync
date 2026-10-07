<?php

namespace Extensions\lazada\Models\Concerns;

use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToLazadaStore
{
    public static function bootBelongsToLazadaStore(): void
    {
        static::creating(function ($model) {
            $model->lazada_setting_id ??= LazadaSetting::defaultStore()?->id;
        });

        static::addGlobalScope('lazadaStore', function (Builder $query) {
            if (app()->bound('lazada.route-store')) {
                $query->where($query->qualifyColumn('lazada_setting_id'), app('lazada.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(LazadaSetting::class, 'lazada_setting_id');
    }

    public function scopeForStore(Builder $query, LazadaSetting|int|null $store): Builder
    {
        $id = $store instanceof LazadaSetting ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('lazada_setting_id'), $id);
    }
}
