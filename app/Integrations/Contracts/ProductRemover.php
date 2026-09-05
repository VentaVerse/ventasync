<?php

namespace App\Integrations\Contracts;

/**
 * A catalog delete reaches the channels (the owner, 2026-09-05).
 *
 * The user deleted the product for a reason. A listing that outlives its
 * product is not a queue to manage, it is a leftover: it keeps selling
 * something this system no longer knows, and every page that shows it
 * asks "why is this here, I deleted it". So the catalog's delete asks
 * every channel to remove the product first, marketplace and local rows
 * alike, and refuses to delete the catalog row while any channel says no.
 *
 * Presence, one entry per store the product sits on:
 *   [
 *     'channel'   => 'Shopee',        // the channel's display name
 *     'store'     => 'Main store',    // the store's own name
 *     'item'      => '123456',        // the marketplace's id, '' when none
 *     'live'      => true|false|null, // the mirror: up, gone, never checked
 *     'removable' => true,            // false: the channel has no delete API
 *   ]
 *
 * Removal, per product:
 *   [
 *     'ok'      => bool,             // false when any store refused
 *     'removed' => ['Shopee (Main store) item 123456', ...],
 *     'kept'    => ['The product stays on Shopify (Store); remove it there.'],
 *     'errors'  => ['Shopee (Main store): access token expired.'],
 *   ]
 * On refusal nothing of the refusing store is cleaned, so a retry sees
 * the same rows; stores that succeeded are cleaned as they succeed.
 */
interface ProductRemover
{
    /**
     * @param list<int> $productIds
     * @return array<int, list<array{channel:string, store:string, item:string, live:?bool, removable:bool}>>
     */
    public function productPresence(array $productIds): array;

    /**
     * @return array{ok:bool, removed:list<string>, kept:list<string>, errors:list<string>}
     */
    public function removeProduct(int $productId): array;

    /**
     * Product ids this channel still holds rows for although the catalog
     * product is gone: what deletes before this contract left behind.
     *
     * @return list<int>
     */
    public function strandedProductIds(): array;
}
