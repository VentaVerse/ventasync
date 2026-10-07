import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#lazada-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to Lazada',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' listing' : ' listings'),
        emptyMessage: 'Select at least one listing first.',
    });
});
