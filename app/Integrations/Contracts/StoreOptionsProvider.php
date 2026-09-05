<?php

namespace App\Integrations\Contracts;

/**
 * Per-store reports (the owner, 2026-09-05): a channel lists its stores as
 * filter choices for the dashboard and the profitability reports.
 *
 * Every existing store counts, paused ones included, because a paused
 * store still has orders. A deleted store is not listed; its orders keep
 * the name stamped on them and stay inside the channel's totals.
 *
 * Each option is shaped:
 *   [
 *     'key'        => 'shopee:2'      // App\Support\StoreKey, what a filter carries
 *     'label'      => 'Main store'    // the store's own name
 *     'channel'    => 'Shopee'        // the heading the store sits under
 *     'source'     => 'shopee'        // Order::marketplace_source
 *     'store_id'   => 2               // Order::store_id, null when the source names the store
 *     'permission' => 'view_shopee/order'
 *   ]
 */
interface StoreOptionsProvider
{
    /**
     * @return list<array{key:string, label:string, channel:string, source:string, store_id:?int, permission:string}>
     */
    public function availableStoreOptions(): array;
}
