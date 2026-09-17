import './bootstrap';
import './sla-countdown';
import './invoice-copy';
import './permission-pill';
import './credential-mask';
import './confirm-modal';
import './busy-overlay';
import './channel-order-fetch';
import './automation-run';
import './thumb-fallback';
import './plot-readout';
import './list-filters';
import './nav-progress';
import './order-selection';
import './order-thumb-preview';
import './field-a11y';
import './hint-place';
import './desk-tools';
import './legacy-flash';
import './pages/shopee-orders';
import './pages/lazada-orders';
import './pages/tiktok-orders';
import './pages/shopify-orders';
import './pages/venta-orders';
import './pages/pedallion';
import './pages/pedallion-listings';
import './pages/order-detail';
import './pages/product-form';
import './pages/shopee-listings';
import './pages/lazada-listings';
import './pages/tiktok-listings';
import './pages/venta-listings';
import './pages/woocommerce-listings';
import './pages/channel-settings';
import './pages/channel-setup';
import './pages/store-settings';
import './pages/group-form';
import './pages/description-template';
import './pages/description-preview';
import './pages/image-library';
import './pages/product-video';
import './pages/watermark-form';
import './pages/listing-images';
import './pages/catalog-change';
import './pages/listing-status-menu';
import './pages/row-picked';
import './pages/listing-group-band';
import './pages/listing-strip';
import './pages/unsaved-guard';
import './pages/gap-jump';
import './pages/required-group';
import './pages/group-products';
import './pages/channel-listing-form';
import './pages/channel-import';
import './pages/channel-add';
import './pages/channel-add-panel';
import './pages/purchasing';
import './pages/product-vendors';
import './pages/warehousing';
import './pages/reports';
import './pages/reviews';
import './pages/settings';
import './pages/ai-tools';
import './pages/order-form';
import './pages/push-review';

import { initNavGroups } from './nav-groups';
import xenonPalette from './palette';
import pettyCash from './pages/petty-cash';
import credentialField from './credential-field';

import Alpine from 'alpinejs';

// app.js is a module script, deferred like the rest of the document, so it
// runs after HTML parsing completes - after first paint, not before. It
// cannot be what prevents the open/closed flash on load; that is now
// handled pre-paint by partials/nav-boot.blade.php (a synchronous inline
// script in <head>, same pattern as theme-boot.blade.php) together with the
// matching rules in layout/shell.css. What initNavGroups() still owns: it
// applies the .is-open class so state stays correct for the click handler
// below, and it is where localStorage gets written back on toggle.
initNavGroups();

window.Alpine = Alpine;
Alpine.data('xenonPalette', xenonPalette);
Alpine.data('pettyCash', pettyCash);
Alpine.data('credentialField', credentialField);
Alpine.start();

/**
 * Mark the shell ready one frame after load.
 *
 * The nav's collapse animates on `.bl-app.is-ready` only. Without this the
 * open group transitioned open on every page load, so clicking a child link
 * looked like the sidebar collapsed and re-opened itself. The open state is
 * rendered by the server, so the first paint is already correct and static;
 * from the next frame on, every toggle animates as before.
 */
requestAnimationFrame(() => {
    requestAnimationFrame(() => document.querySelector('.bl-app')?.classList.add('is-ready'));
});
