<?php

namespace Extensions\shopee\Models\Concerns;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row that belongs to one Shopee store (multi-store S2, #194).
 *
 * The creating hook is the bridge between stages: every write path
 * that does not yet name its store (S3 threads them all) is stamped
 * with the default store, so the NOT NULL column never bites a code
 * path the migration got ahead of. A caller that knows its store sets
 * shopee_setting_id itself and the hook steps aside (??=).
 */
trait BelongsToShopeeStore
{
    public static function bootBelongsToShopeeStore(): void
    {
        static::creating(function ($model) {
            $model->shopee_setting_id ??= ShopeeSetting::defaultStore()?->id;
        });

        /* THE READ FENCE (S4b, #194). On a store page (ResolveShopeeStore
         * bound the row) every query on a store-owned model is the store's
         * own - ~180 read sites became store-correct here instead of one
         * by one, and an upsert's lookup half now finds only the store's
         * row, matching what the creating hook stamps. Outside a store
         * request (console, webhooks, the hub before a panel binds) the
         * closure adds nothing, so crons that thread explicit stores keep
         * their own scoping. Cross-store work under a bound store says so:
         * withoutGlobalScope('shopeeStore'). */
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
