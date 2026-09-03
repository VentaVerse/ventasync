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
    // confirm-modal.js re-submits an accepted form via requestSubmit(),
    // which runs these listeners again, so the overlay rises then too.
    document.querySelectorAll('form[data-slow-action]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            overlay.classList.add('active');
        });
    });

    /* Import selected (the owner, 2026-09-04): tick rows, one press imports
       them all. The checkboxes sit in the table but belong to the bulk
       form through the form="" attribute, so no id injection is needed -
       this only keeps the count honest and the button earned. */
    var bulk = document.getElementById('cc-import-selected');
    if (!bulk) return;
    var all = document.querySelector('[data-import-check-all]');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-import-check]'));
    var button = bulk.querySelector('[data-import-selected-button]');

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        if (button) {
            button.disabled = n === 0;
            button.textContent = n > 0 ? 'Import selected (' + n + ')' : 'Import selected';
        }
        if (all) {
            all.checked = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        var one = bulk.getAttribute('data-confirm-one') || '';
        var many = bulk.getAttribute('data-confirm-many') || '';
        if (one && many) {
            bulk.setAttribute('data-confirm', n === 1 ? one : many.replace(':n', String(n)));
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
});
