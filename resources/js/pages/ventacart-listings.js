import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('ventacart-listings-page');
    if (!root) return;
    var store = root.getAttribute('data-store-name') || 'the store';

    initChannelListings({
        overlaySelector: '#ventacart-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to ' + store,
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
