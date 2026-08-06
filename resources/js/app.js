import './bootstrap';
import './sla-countdown';
import './invoice-copy';
import './permission-pill';
import './credential-mask';
import './confirm-modal';
import './orders-filter';

import { initNavGroups } from './nav-groups';

import Alpine from 'alpinejs';

// Runs immediately (not on DOMContentLoaded): app.js is a module script,
// deferred like the rest of the document, so by the time it executes the
// nav markup already exists and no paint has happened yet. Reading the
// stored open/closed state here, before Alpine or anything else, is what
// keeps groups from visibly flashing open on load.
initNavGroups();

window.Alpine = Alpine;
Alpine.start();
