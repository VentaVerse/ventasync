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
