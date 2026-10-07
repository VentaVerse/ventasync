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
