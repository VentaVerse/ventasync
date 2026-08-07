/**
 * Venta Orders page
 * (extensions/venta/views/orders/_panel.blade.php, rendered by both a Venta
 * store's seller center and the Fulfilment hub).
 *
 * This used to be an inline <script> block pushed onto the page's own stack.
 * It moves here as part of that page's exit from the .x-legacy boundary: a
 * new-UX page owns no inline script, the same way it owns no inline style.
 * Bundled into the shared app.js (every layout loads it) and guarded on
 * #venta-orders-page, so it is inert everywhere else, which is the same
 * convention the other three channels' page modules already use.
 *
 * All it does is drive the selection bar: the bar is the only place a
 * destructive control appears on this page, and it appears only once the
 * operator has picked something for it to act on. Three things changed while
 * moving, all of them defects rather than design:
 *
 *   1. The inline version was not guarded. It ran on every page in the app,
 *      called bulkBar.style.display on an element that exists on exactly one
 *      of them, and threw on all the others, killing whatever else the same
 *      pass would have done.
 *
 *   2. Visibility was driven by writing style.display from JS, which is an
 *      inline style by another name. The bar's form now carries the [hidden]
 *      attribute and CSS owns what hidden looks like.
 *
 *   3. The count was written but never announced. The bar's count element is
 *      a live region now, and its text says what is selected in words rather
 *      than as a bare number beside a label the reader has to join up.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('venta-orders-page');
    if (!page) return;

    var selectAll = page.querySelector('#venta-select-all');
    var bulkForm = page.querySelector('#venta-bulk-delete-form');
    var countEl = page.querySelector('#venta-bulk-count');

    // The selection bar only renders for the manage tier and only when the
    // list is non-empty, so a read-only operator or an empty page reaches
    // this point with nothing to bind.
    if (!selectAll || !bulkForm || !countEl) return;

    function boxes() {
        return Array.prototype.slice.call(page.querySelectorAll('.venta-order-check'));
    }

    function update() {
        var all = boxes();
        var picked = all.filter(function (b) { return b.checked; });

        bulkForm.hidden = picked.length === 0;
        countEl.textContent = picked.length === 0
            ? ''
            : picked.length + (picked.length === 1 ? ' order selected' : ' orders selected');

        selectAll.checked = all.length > 0 && picked.length === all.length;
        selectAll.indeterminate = picked.length > 0 && picked.length < all.length;
    }

    selectAll.addEventListener('change', function () {
        boxes().forEach(function (b) { b.checked = selectAll.checked; });
        update();
    });

    // Delegated rather than bound per checkbox: the rows are re-rendered on
    // every filter, sort and page change, and a delegated listener survives
    // that without this module needing to know when it happened.
    page.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('venta-order-check')) {
            update();
        }
    });

    update();
});
