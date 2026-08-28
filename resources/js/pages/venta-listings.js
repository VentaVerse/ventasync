/* Venta listings index (#171): the same module Shopee's, Lazada's and
   TikTok's listings pages ride (channel-listings.js), bound to this
   page's own overlay and words. The store's name is on the page root. */
import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('venta-listings-page');
    if (!root) return;
    var store = root.getAttribute('data-store-name') || 'the store';

    initChannelListings({
        overlaySelector: '#venta-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to ' + store,
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
