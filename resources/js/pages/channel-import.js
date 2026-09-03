/*
 * The Import pages (cleanup points 10-11): one page per channel under the
 * Catalog menu, every one a clone of the same queue table.
 *
 * When the queue left each listings workbench for its own page, it left the
 * workbench's JS behind: channel-listings.js is deliberately inert without
 * that page's own overlay, so nothing bound the catalog typeahead here and
 * every Link button sat permanently disabled. This module owns the import
 * pages instead, keyed on the overlay component they all include.
 *
 * The behaviour itself still lives in channel-listings.js - same picker,
 * same wire (GET search-catalog?q=, picked id into the hidden product_id).
 */
import { initCatalogPicker } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('channel-import-progress');
    if (!overlay) return;

    document.querySelectorAll('form[data-unmatched-link]').forEach(initCatalogPicker);

    // Scan and Import are real marketplace round-trips; the overlay is the
    // sign the press landed. Bubble phase + defaultPrevented so a guarded
    // form's cancelled confirm never raises it (see busy-overlay.js).
    document.querySelectorAll('form[data-slow-action]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            overlay.classList.add('active');
        });
    });
});
