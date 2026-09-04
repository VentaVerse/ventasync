<?php

namespace Extensions\tiktok\Models\Concerns;

use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row that belongs to one TikTok store (multi-store, the owner 2026-09-05).
 *
 * Ported verbatim in spirit from BelongsToLazadaStore / BelongsToShopeeStore.
 * Two hooks do the whole partitioning:
 *
 *   THE STAMP. Every write path that does not name its store is tagged with
 *   the default store on `creating` (??=), so a caller that already knows its
 *   store sets tiktok_setting_id itself and the hook steps aside. An upsert
 *   whose match half never mentioned the store still lands in the right one.
 *
 *   THE READ FENCE. On a store page ResolveTikTokStore has bound the row, and
 *   every query on a store-owned model becomes that store's own - the
 *   controller read sites became store-correct here instead of one by one, and
 *   an upsert's lookup half finds only the store's row, matching what the stamp
 *   writes. Outside a store request (console commands, the hub before a panel
 *   binds, the OAuth callback before it binds) the closure adds nothing, so
 *   crons that thread explicit stores keep their own scoping. Deliberate
 *   cross-store work under a bound store says so with
 *   withoutGlobalScope('tiktokStore').
 */
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
