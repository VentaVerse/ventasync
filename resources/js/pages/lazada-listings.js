/*
 * Lazada Listings (/channels/lazada/products).
 *
 * All of this behaviour used to be an inline <script> at the bottom of
 * extensions/lazada/views/products/index.blade.php: the select-all and bulk
 * bar, the bulk-id injection, the loading overlay, a hand-rolled row-action
 * dropdown, and the unmatched-item catalog typeahead. That view has been
 * rebuilt on the design tokens and left the .x-legacy boundary, and this
 * project does not put behaviour in Blade.
 *
 * What is left here is only Lazada's three values; the behaviour itself lives
 * in ./channel-listings.js, shared with Shopee. Two pieces of the original
 * script are gone rather than ported, because the rebuilt view no longer has
 * anything for them to drive:
 *
 *   - the row-action dropdown (open/close, outside-click, .menu-open on the
 *     table wrapper) is now <x-ui.menu>, which Alpine drives;
 *   - the select-all checkbox, the selected count and the bulk bar's
 *     visibility are Alpine's x-model / x-show in the view. This module keeps
 *     only the part Alpine cannot do: injecting the ticked ids into whichever
 *     bulk form was submitted.
 *
 * Lazada's bulk forms post `listing_ids[]`, keyed on the lazada_products row
 * id, not on the catalog product id. That is the pre-redesign wire and the
 * reason this file exists separately from Shopee's.
 *
 * Everything else is unchanged at the wire: the same five bulk routes, the
 * same `product_id` on the unmatched link form, the same GET to
 * search-catalog?q=, the same 300ms debounce and the same two-character
 * minimum.
 */

import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#lazada-listings-progress',
        bulkField: 'listing_ids[]',
        slowTitle: 'Talking to Lazada',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' listing' : ' listings'),
        emptyMessage: 'Select at least one listing first.',
    });
});
