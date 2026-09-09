/**
 * The wait after a confirmed marketplace write.
 *
 * Shopee's ship flow runs as a fetch from the page, so its script owns the
 * whole wait and can say "Shipping order" and then "Generating AWB" while
 * the marketplace answers. Lazada's pack, arrange and recreate, and TikTok's
 * ship, are ordinary form submits: the browser leaves the page, and for the
 * several seconds the marketplace takes, the only sign anything is happening
 * was the thin progress line at the top. A packer pressed Pack, saw nothing
 * move, and pressed it again.
 *
 * Any form, or any submit button, that carries data-busy="Packing this order
 * on Lazada" raises the page's loading overlay with that title the moment its
 * submit is really going out. "Really" matters: confirm-modal.js
 * preventDefaults the first submit of a guarded form from a capture-phase
 * listener and re-submits it once the dialog is accepted, so this listens in
 * the bubble phase and honours defaultPrevented, exactly as the per-page
 * fetch overlays do. A Cancel never raises anything.
 *
 * The overlay is the one the page already has for Fetch and Update, found
 * by data-busy-overlay, with its title under data-busy-title. Forms that
 * open a document in a new tab are left alone: the page is not going
 * anywhere, so there is nothing to wait for on it.
 *
 * The count form of the attribute, data-busy-count="Packing :n orders", is
 * filled in by order-selection.js as the selection changes, the same way it
 * fills data-confirm from data-confirm-count.
 */
(function () {
    function overlay() {
        return document.querySelector('[data-busy-overlay]');
    }

    function show(title) {
        var el = overlay();
        if (!el) return;

        var titleEl = el.querySelector('[data-busy-title]');
        if (titleEl && title) titleEl.textContent = title;

        el.classList.add('active');
    }

    function hide() {
        var el = overlay();
        if (el) el.classList.remove('active');
    }

    document.addEventListener('submit', function (e) {
        // A submit that will not happen must not raise the overlay. See the
        // note above the submit handler in confirm-modal.js.
        if (e.defaultPrevented) return;

        var form = e.target;
        var submitter = e.submitter || null;
        var title = (submitter && submitter.getAttribute('data-busy'))
            || (form.getAttribute && form.getAttribute('data-busy'));
        if (!title) return;

        var target = (submitter && submitter.getAttribute('formtarget')) || form.target;
        if (target === '_blank') return;

        show(title);
    });

    /* A SUBMIT NOBODY CAN SEE.
     *
     * form.submit() does not fire a submit event, so a page that posts a
     * form from script - the push review modal does, because it copies the
     * edited title and description in first - left the operator on a page
     * that looked idle while Shopee took ten seconds to answer. He pressed
     * Push and nothing happened (the owner, 2026-09-09).
     *
     * Rather than let each such page raise its own overlay, they say what
     * they are doing and this raises the one overlay the page already has.
     *   document.dispatchEvent(new CustomEvent('busy:show', {
     *       detail: { title: 'Pushing to Shopee' } }));
     */
    document.addEventListener('busy:show', function (e) {
        show((e.detail && e.detail.title) || '');
    });

    // The back button restoring this page from the cache would otherwise
    // bring the overlay back with it, still saying "Packing".
    window.addEventListener('pageshow', hide);
})();
