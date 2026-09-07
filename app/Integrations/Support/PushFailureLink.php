<?php

namespace App\Integrations\Support;

use App\Models\ScheduledJob;
use Illuminate\Support\Facades\Route;

/**
 * Where the products a failed push left behind can be seen (the owner,
 * 2026-09-07). A Push Stock or Push Price row that last ran red says
 * "Failed" and nothing else; the products it failed are already one
 * filter away on the channel's Listings page (sync_status=error), so the
 * row links there instead of growing a page of its own.
 */
final class PushFailureLink
{
    /** integration => the Listings route that takes a sync_status filter */
    private const LISTINGS = [
        'shopee' => 'ext.shopee.products.index',
        'lazada' => 'ext.lazada.products.index',
        'tiktok' => 'ext.tiktok.products.index',
        'venta' => 'ext.venta.listings.index',
        'woocommerce' => 'ext.woocommerce.listings.index',
    ];

    /** A stock or price push, whatever the channel calls its command. */
    public static function isPush(ScheduledJob $job): bool
    {
        return (bool) preg_match('/:push-(stock|price|qty)$/', (string) strtok(trim((string) $job->command), ' '));
    }

    /**
     * The filtered Listings URL for a push row whose last run failed, or
     * null when the row is not a push, did not fail, or the channel has no
     * Listings page to point at. The marketplace rows carry no store id;
     * on a store's page the route's {store} comes from the URL default the
     * store middleware set.
     */
    public static function for(ScheduledJob $job, ?int $storeId = null): ?string
    {
        if ($job->last_run_ok !== false || ! self::isPush($job)) {
            return null;
        }
        $name = self::LISTINGS[(string) $job->integration] ?? null;
        if ($name === null || ! Route::has($name)) {
            return null;
        }
        $params = ['sync_status' => 'error'];
        $store = $storeId ?? ($job->store_id !== null ? (int) $job->store_id : null);
        if ($store) {
            $params['store'] = $store;
        }
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
