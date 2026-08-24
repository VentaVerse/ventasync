/*
 * Shopee Listings (/channels/shopee/products).
 *
 * All of this behaviour used to be an inline <script> at the bottom of
 * extensions/shopee/views/products/index.blade.php. That view was rebuilt on
 * the design tokens in Round C Task 1 and no longer lives inside the
 * .x-legacy boundary, and this project does not put behaviour in Blade.
 *
 * Round C Task 2 brought Lazada's listings page onto the same anatomy, which
 * needs the same overlay, bulk-id injection, slow-action overlay and
 * catalog typeahead. Rather than a second copy of ~250 lines, the shared
 * work moved to ./channel-listings.js and this file is what remains: the
 * three values that are genuinely Shopee's. Shopee's DOM contract is
 * unchanged, so the view was not touched.
 *
 * The wire is unchanged from the inline original: the same `product_ids[]`
 * hidden inputs, the same `product_id` on the link form, the same GET to
 * search-catalog?q=, the same 300ms debounce and the same two-character
 * minimum.
 */

import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#shopee-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to Shopee',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
