/*
 * Shopify orders page: the raw-payload dialog.
 *
 * Round F Task 2 rebuilt extensions/shopify/views/orders/index.blade.php on
 * the shared `co-` order anatomy and took it out of the .x-legacy boundary.
 * The page carried an inline <script> at the bottom with two global functions
 * called from onclick attributes; this project does not put behaviour in
 * Blade, so it lives here.
 *
 * The page renders one <script type="application/json"> island per order
 * holding what Shopify sent, and one dialog. The overflow menu on a row names
 * the island it wants; this module copies that island's text into the
 * dialog's <pre> and shows it.
 *
 * SECURITY. The payload is written with textContent, never innerHTML, exactly
 * as the inline original did. The genuine hole was on the other side of the
 * fence and is fixed in the Blade: the islands are now encoded with
 * JSON_HEX_TAG, so a value containing a closing script tag can no longer end
 * the element and run as markup.
 *
 * Bound by id rather than by page, so it works unchanged on both surfaces the
 * panel renders on: the seller center and the fulfilment hub, which carry the
 * same #shopify-orders-page root and the same dialog ids.
 *
 * Three deliberate changes from the inline original, all reported:
 *
 *   1. The two global window functions and their onclick attributes are gone.
 *      Rows carry data-sf-payload / data-sf-order and this module listens.
 *   2. The dialog no longer sets style.display; it uses the same
 *      .modal-backdrop.active class every other dialog in the application
 *      uses, so one stylesheet draws all of them.
 *   3. The Escape handler was bound to document unconditionally on EVERY page
 *      in the application, and called getElementById on a dialog that was not
 *      there. It is bound only when this page's dialog exists, and it only
 *      acts while the dialog is open.
 */

function initShopifyOrders() {
    const modal = document.getElementById('sfPayloadModal');
    if (!modal) return;

    const pre = document.getElementById('sfPayloadPre');
    const title = document.getElementById('sfPayloadTitle');
    if (!pre) return;

    let lastTrigger = null;

    function close() {
        modal.classList.remove('active');
        pre.textContent = '';
        if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
        lastTrigger = null;
    }

    function open(trigger) {
        const island = document.getElementById(trigger.getAttribute('data-sf-payload') || '');
        const order = trigger.getAttribute('data-sf-order') || '';

        if (title) title.textContent = order ? 'Raw payload ' + order : 'Raw payload';

        // An order synced before the payload column was populated has an
        // island holding "null". Say so rather than showing an empty box that
        // reads as a page that failed to load.
        const text = island ? (island.textContent || '').trim() : '';
        pre.textContent = text !== '' && text !== 'null'
            ? text
            : 'Nothing was stored for this order.';

        lastTrigger = trigger;
        modal.classList.add('active');
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-sf-payload]');
        if (trigger) {
            event.preventDefault();
            open(trigger);
            return;
        }

        // The backdrop itself, not anything inside the dialog.
        if (event.target === modal) close();
    });

    ['btnCloseSfPayload', 'btnCloseSfPayload2'].forEach(function (id) {
        const button = document.getElementById(id);
        if (button) button.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) close();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initShopifyOrders);
} else {
    initShopifyOrders();
}
