import './bootstrap';
import './sla-countdown';
import './invoice-copy';
import './permission-pill';
import './credential-mask';
import './confirm-modal';
import './thumb-fallback';
import './plot-readout';
import './list-filters';
import './legacy-flash';
import './pages/shopee-orders';
import './pages/lazada-orders';
import './pages/tiktok-orders';
import './pages/venta-orders';
import './pages/shopify-orders';
import './pages/pedallion';
import './pages/pedallion-listings';
import './pages/order-detail';
import './pages/product-form';
import './pages/shopee-listings';
import './pages/lazada-listings';
import './pages/channel-settings';
import './pages/store-settings';
import './pages/group-form';
import './pages/group-products';
import './pages/channel-listing-form';
import './pages/purchasing';
import './pages/product-vendors';
import './pages/warehousing';
import './pages/reports';
import './pages/reviews';
import './pages/settings';
import './pages/order-form';

import { initNavGroups } from './nav-groups';
import xenonPalette from './palette';
import pettyCash from './pages/petty-cash';

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
Alpine.start();
