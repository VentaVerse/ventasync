/*
 * Pedallion Listings (/integrations/pedallion/products).
 *
 * All of this behaviour used to be an inline <script> at the bottom of
 * extensions/pedallion/views/products/index.blade.php. Round F Task 2 rebuilt
 * that view on the design tokens and took it out of the .x-legacy boundary,
 * and this project does not put behaviour in Blade.
 *
 * The shared work (overlay, bulk-id injection, slow-action overlay) lives in
 * ./channel-listings.js, which Shopee and Lazada already use. This file is
 * what is genuinely Pedallion's: its four labels, and the default id its bulk
 * endpoints take.
 *
 * `bulkField` here is the DEFAULT, not the only answer. Pedallion is the one
 * channel whose bulk endpoints do not all speak the same id: two take
 * `product_ids[]` (any catalog product) and four take `link_ids[]` (rows
 * already linked to Pedallion). The four declare that on the form itself,
 * with data-bulk-field and data-bulk-attr; see the note at the top of the
 * Blade. The wire is identical to the inline original, which decided the same
 * thing by matching on a CSS class.
 */

import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#pedallion-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to Pedallion',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
