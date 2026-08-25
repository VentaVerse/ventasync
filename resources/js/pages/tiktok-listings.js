/* TikTok Shop listings page (#156): the same module Shopee's and Lazada's
   listings pages ride (channel-listings.js), bound to this page's own
   overlay and words. */
import { initChannelListings } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#tiktok-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to TikTok Shop',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });
});
