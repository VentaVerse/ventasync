<?php

namespace Extensions\tiktok\Models\Concerns;

use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTikTokStore
{
    public static function bootBelongsToTikTokStore(): void
    {
        static::creating(function ($model) {
            $model->tiktok_setting_id ??= TikTokSetting::defaultStore()?->id;
        });

        static::addGlobalScope('tiktokStore', function (Builder $query) {
            if (app()->bound('tiktok.route-store')) {
                $query->where($query->qualifyColumn('tiktok_setting_id'), app('tiktok.route-store')->id);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(TikTokSetting::class, 'tiktok_setting_id');
    }

    public function scopeForStore(Builder $query, TikTokSetting|int|null $store): Builder
    {
        $id = $store instanceof TikTokSetting ? $store->id : $store;

        return $id === null ? $query : $query->where($query->qualifyColumn('tiktok_setting_id'), $id);
    }
}
