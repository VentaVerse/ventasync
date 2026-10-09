<?php

namespace Extensions\TikTok\Models\Concerns;

use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTikTokStore
{
    public static function bootBelongsToTikTokStore(): void
    {
        static::creating(function ($model) {
            $model->store_id ??= TikTokStore::defaultStore()?->id;
        });

        static::addGlobalScope('tiktokStore', function (Builder $query) {
            if (app()->bound('tiktok.route-store')) {
                $query->where($query->qualifyColumn('store_id'), app('tiktok.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(TikTokStore::class, 'store_id');
    }

    public function scopeForStore(Builder $query, TikTokStore|int|null $store): Builder
    {
        $id = $store instanceof TikTokStore ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('store_id'), $id);
    }
}
