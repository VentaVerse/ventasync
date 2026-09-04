<?php

namespace Extensions\lazada\Models\Concerns;

use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row that belongs to one Lazada store (multi-store, the owner 2026-09-05).
 *
 * Ported from Shopee's BelongsToShopeeStore, which carried the same design
 * through its own #194 rollout. Two hooks do the whole partitioning:
 *
 *   THE STAMP. Every write path that does not name its store is tagged with
 *   the default store on `creating`, so a caller that already knows its store
 *   sets lazada_setting_id itself and the hook steps aside (??=). This is why
 *   an upsert whose match half never mentioned the store still lands the new
 *   row in the right one.
 *
 *   THE READ FENCE. On a store page ResolveLazadaStore has bound the row, and
 *   every query on a store-owned model becomes that store's own - the ~140
 *   controller read sites became store-correct here instead of one by one,
 *   and an upsert's lookup half finds only the store's row, matching what the
 *   stamp writes. Outside a store request (console commands, the hub before a
 *   panel binds, the OAuth callback before it binds) the closure adds
 *   nothing, so crons that thread explicit stores keep their own scoping.
 *   Deliberate cross-store work under a bound store says so with
 *   withoutGlobalScope('lazadaStore').
 */
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
