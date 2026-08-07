import './bootstrap';
import './sla-countdown';
import './invoice-copy';
import './permission-pill';
import './credential-mask';
import './confirm-modal';
import './orders-filter';
import './legacy-flash';
import './pages/shopee-orders';
import './pages/lazada-orders';
import './pages/tiktok-orders';
import './pages/venta-orders';

import { initNavGroups } from './nav-groups';
import xenonPalette from './palette';

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
Alpine.start();
