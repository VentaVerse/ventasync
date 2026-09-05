/* WooCommerce's Listings and group products pages ride the shared channel
   listings behaviour (channel-listings.js): the bulk bar that injects the
   ticked ids into whichever bulk form is submitted, the "talking to the
   store" overlay on single-row actions, and the verb forms that say what
   they will skip. Bound by the data-woo-listings root; the store's name
   is on it. */
import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-woo-listings]');
    if (!root) return;
    var store = root.getAttribute('data-store-name') || 'the store';
    initChannelListings({
        overlaySelector: '#woocommerce-listings-progress',
        bulkField: root.id === 'woocommerce-group-page' ? 'ids[]' : 'product_ids[]',
        slowTitle: 'Talking to ' + store,
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
