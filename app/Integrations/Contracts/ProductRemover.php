<?php

namespace App\Integrations\Contracts;

/**
 * A catalog delete forgets the product on every channel (the owner, 2026-09-07).
 *
 * The delete is the catalog's own act. It removes the product and every
 * row a channel keeps about it inside VentaSync: links, listings, group
 * memberships, mirrors, caches. It never calls a marketplace and never
 * refuses: the item the seller has on Shopee, Lazada or any store stays
 * there, exactly as it would after a delete in any other tool, and
 * removing it from the store is the channel's own action on the
 * channel's own pages. (The 2026-09-05 rule sent delete calls to the
 * marketplaces and let a refusing store keep the catalog row; the owner:
 * "that is coupling", and a QA catalog could not even be cleaned.)
 *
 * Presence, one entry per store the product sits on, for the confirm
 * sheet and the stranded sweep:
 *   [
 *     'channel' => 'Shopee',        // the channel's display name
 *     'store'   => 'Main store',    // the store's own name
 *     'item'    => '123456',        // the marketplace's id, '' when none
 *     'live'    => true|false|null, // the mirror: up, gone, never checked
 *   ]
 */
interface ProductRemover
{
    /**
     * @param list<int> $productIds
     * @return array<int, list<array{channel:string, store:string, item:string, live:?bool}>>
     */
    public function productPresence(array $productIds): array;

    /**
     * Forget the product on this channel: every local row goes, nothing is
     * sent. Returns the stores whose marketplace item is still up and now
     * unknown to VentaSync, as "Channel (Store)" labels, so the flash can
     * say where the product still lives.
     *
     * @return list<string>
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
