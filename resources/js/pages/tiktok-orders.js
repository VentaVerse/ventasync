/**
 * TikTok Shop Orders page
 * (extensions/tiktok/views/orders/_panel.blade.php, rendered by both the
 * TikTok seller center and the Fulfilment hub).
 *
 * This used to be an inline <script> block at the bottom of the Orders Blade
 * file. It moves here as part of that page's exit from the .x-legacy
 * boundary: a new-UX page owns no inline script, the same way it owns no
 * inline style. Bundled into the shared app.js (every layout loads it) and
 * guarded on #tiktok-orders-page, so it is inert everywhere else. That is
 * the same convention shopee-orders.js and lazada-orders.js already use.
 *
 * Behaviour is the same flow as the inline version: same endpoint, same
 * modal, same tracking payload shapes. Four things changed while moving, all
 * of them defects rather than design:
 *
 *   1. The inline version was not guarded at all. It ran on every page in
 *      the app and called addEventListener on
 *      document.getElementById('btnCloseTtTracking') unconditionally, which
 *      threw on every page that does not have that button, killing whatever
 *      else the same DOMContentLoaded pass would have done. Half of it (the
 *      image preview) also sat outside the DOMContentLoaded callback and
 *      appended a preview node to document.body on literally every page
 *      load.
 *
 *   2. innerHTML. Two of the tracking modal's own labels were written as
 *      markup strings. None of that data was user-controlled, but a module
 *      that never writes markup as a string has no escaping to get wrong
 *      later, when a field that IS user-controlled joins the same builder.
 *      Every node here is createElement/textContent.
 *
 *   3. The Blade file interpolated session('...')['awb_url'] straight into a
 *      window.open() call. That value now rides on the page root as a data
 *      attribute, escaped by Blade, and is resolved against this page's own
 *      origin before it is opened: a value that reaches window.open() should
 *      not be trusted on provenance alone.
 *
 *   4. Failure paths. The tracking fetch left the modal reading "Loading" if
 *      the request threw before its own catch could run, and the fetch form
 *      had no client-side date check at all, so a submit the server was
 *      always going to reject still put the operator through a round trip.
 *      Every branch below ends with the overlay hidden or the body showing a
 *      real message, and nothing is disabled without a path that re-enables
 *      it.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('tiktok-orders-page');
    var detailPage = document.getElementById('tiktok-order-detail-page');
    if (!page && !detailPage) return;

    if (page) {
        openPendingAwb(page);
        initFetchUpdateOverlay();
        initTrackingModal();
        initImagePreview();
    }

    // The order detail page (extensions/tiktok/views/orders/show.blade.php)
    // is a second document with a second root id, not a section of the list.
    // It carries the same tracking modal - one copy of that markup lives in
    // orders/_modals.blade.php and both pages include it - and its Tracking
    // button is the same .btnTtTracking contract, so the flow is the module's
    // existing one rather than a second implementation.
    //
    // It has no fetch/update command bar and no session waybill to open, so
    // neither initialiser is called here. initImagePreview() is deliberately
    // not called either: this page's thumbnails are already the 96px the
    // list's preview zooms up to, which is what
    // `.od-page .order-img-wrap { cursor: default }` already states.
    if (detailPage) {
        initTrackingModal();
        initBrokenImages(detailPage);
    }

    /* ------------------------------------------------------------------
       Shared helpers.
       ------------------------------------------------------------------ */

    function showModal(el) { if (el) el.classList.add('active'); }
    function hideModal(el) { if (el) el.classList.remove('active'); }

    function clear(el) {
        while (el && el.firstChild) el.removeChild(el.firstChild);
    }

    function textNode(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        el.textContent = text == null ? '' : String(text);
        return el;
    }

    // Closes a modal on its backdrop, its close buttons, and Escape.
    function bindModalDismiss(modal, buttons) {
        if (!modal) return;
        (buttons || []).forEach(function (btn) {
            if (btn) btn.addEventListener('click', function () { hideModal(modal); });
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) hideModal(modal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) hideModal(modal);
        });
    }

    /* ------------------------------------------------------------------
       A waybill produced by the ship request that just finished is handed
       back through the session and opened once, here.
       ------------------------------------------------------------------ */
    function openPendingAwb(root) {
        var raw = root.getAttribute('data-awb-url') || '';
        if (!raw) return;

        // Resolve against this page's own origin and refuse anything that
        // lands elsewhere. A relative path stays a relative path; an
        // absolute URL to another host is dropped rather than opened.
        var resolved;
        try {
            resolved = new URL(raw, window.location.origin);
        } catch (e) {
            return;
        }
        if (resolved.origin !== window.location.origin) return;

        window.open(resolved.href, '_blank', 'noopener');
    }

    /* ------------------------------------------------------------------
       Loading overlay on Fetch / Update.
       ------------------------------------------------------------------ */
    function initFetchUpdateOverlay() {
        var overlay = document.getElementById('ttLoadingOverlay');
        var title = document.getElementById('ttLoadingTitle');
        var fetchForm = document.getElementById('formFetchTiktokOrders');
        if (!fetchForm) return;

        var clickedBtn = null;
        var fetchBtn = document.getElementById('btnFetchOrders');
        var updateBtn = document.getElementById('btnUpdateOrders');

        if (fetchBtn) fetchBtn.addEventListener('click', function () { clickedBtn = 'fetch'; });
        if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

        fetchForm.addEventListener('submit', function (e) {
            // A submit that will not happen must not raise the overlay: the
            // confirmation dialog preventDefaults this same event from a
            // capture-phase listener, and Cancel never takes an overlay down.
            // See the note above the submit handler in confirm-modal.js.
            if (e.defaultPrevented) return;

            var isUpdate = clickedBtn === 'update';

            // Update statuses refreshes a fixed 30-day window server-side and
            // never reads the dates, so it is not held to them. Fetch is, and
            // is validated before anything is shown: a rejected submit must
            // not leave an overlay covering the page it refused to leave.
            if (!isUpdate) {
                var dateFrom = fetchForm.querySelector('input[name="date_from"]');
                var dateTo = fetchForm.querySelector('input[name="date_to"]');

                if (!dateFrom || !dateTo || !dateFrom.value || !dateTo.value) {
                    e.preventDefault();
                    hideModal(overlay);
                    if (window.showFlashError) {
                        window.showFlashError('Please provide both From and To dates.');
                    }
                    return;
                }
            }

            if (title) {
                title.textContent = isUpdate ? 'Updating orders' : 'Fetching orders';
            }
            showModal(overlay);
        });
    }

    /* ------------------------------------------------------------------
       Tracking modal. The endpoint answers with TikTok's own payload
       wrapped in an { ok, body, tracking_number, shipping_carrier } shell,
       and the event list sits under one of three keys depending on which of
       its endpoints served the request.
       ------------------------------------------------------------------ */
    function initTrackingModal() {
        var modal = document.getElementById('ttTrackingModal');
        var body = document.getElementById('ttTrackingBody');
        var sub = document.getElementById('ttTrackingSub');
        if (!modal || !body) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseTtTracking'),
            document.getElementById('btnCloseTtTracking2')
        ]);

        // TikTok sends Unix timestamps, in seconds on some fields and
        // milliseconds on others. Both are recognised; anything already
        // formatted as text is passed through untouched.
        function formatEventTime(t) {
            if (!t) return '';
            if (typeof t === 'number' && t > 1000000000000) {
                var d = new Date(t);
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
                    + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            }
            if (typeof t === 'number' && t > 1000000000) {
                return formatEventTime(t * 1000);
            }
            return String(t);
        }

        function collectEvents(payload) {
            var data = (payload && payload.data) ? payload.data : payload;
            if (!data) return [];
            var events = data.tracking || data.tracking_info || data.events || [];
            return Array.isArray(events) ? events : [];
        }

        function renderTracking(res) {
            clear(body);
            if (sub) sub.textContent = '';

            if (!res || res.ok !== true) {
                var apiBody = (res && res.body) ? res.body : {};
                var msg = (res && res.message) ? res.message
                    : (apiBody.message ? apiBody.message : 'TikTok could not be reached for this order.');
                body.appendChild(textNode('div', 'text-danger', msg));
                return;
            }

            var apiPayload = res.body || {};
            var data = apiPayload.data || apiPayload;
            var trackNum = res.tracking_number || data.tracking_number || '';
            var carrier = res.shipping_carrier || data.shipping_provider || '';

            // Carrier and tracking number are the header of the answer, not
            // events in it, so they sit in the modal's meta line rather than
            // as two more rows above the timeline.
            if (sub) {
                var parts = [];
                if (carrier) parts.push(carrier);
                if (trackNum) parts.push(trackNum);
                sub.textContent = parts.join(' · ');
            }

            var events = collectEvents(apiPayload);
            if (events.length === 0) {
                body.appendChild(textNode('div', 'text-secondary', 'TikTok returned no tracking events for this order.'));
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'co-trace';

            events.forEach(function (ev) {
                var row = document.createElement('div');
                row.className = 'co-trace__event';
                row.appendChild(textNode(
                    'div',
                    'co-trace__time',
                    formatEventTime(ev.update_time_millis || ev.update_time || ev.time || ev.update_time_text || '')
                ));
                row.appendChild(textNode('div', 'co-trace__status', ev.description || ev.message || ev.status || ''));

                var detail = ev.detail || ev.location || '';
                if (detail) row.appendChild(textNode('div', 'co-trace__detail', detail));

                wrap.appendChild(row);
            });

            body.appendChild(wrap);
        }

        function loadTracking(url) {
            clear(body);
            if (sub) sub.textContent = '';
            body.appendChild(textNode('div', 'text-secondary', 'Loading'));

            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(renderTracking)
                .catch(function () {
                    // Covers both a network failure and a response that is
                    // not JSON at all, either of which used to leave the
                    // modal reading "Loading" forever.
                    clear(body);
                    if (sub) sub.textContent = '';
                    body.appendChild(textNode('div', 'text-danger', 'Could not load tracking for this order.'));
                });
        }

        // Delegated, so it keeps working for rows added by anything later.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnTtTracking') : null;
            if (!btn) return;
            var url = btn.getAttribute('data-url');
            if (!url) return;
            e.preventDefault();
            showModal(modal);
            loadTracking(url);
        });
    }

    /* ------------------------------------------------------------------
       Broken item thumbnails. The previous version of the detail page hid
       these with an inline onerror="" attribute, which this project does
       not allow. A capture-phase listener on the page root does the same
       job (the error event does not bubble, so capture is what reaches it)
       with no attribute and no inline script.
       ------------------------------------------------------------------ */
    function initBrokenImages(root) {
        root.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
            img.hidden = true;
        }, true);
    }

    /* ------------------------------------------------------------------
       Thumbnail hover preview (order-img-wrap / img.order-img).
       ------------------------------------------------------------------ */
    function initImagePreview() {
        if (document.querySelector('.order-img-preview')) return;

        var preview = document.createElement('div');
        preview.className = 'order-img-preview';
        var previewImg = document.createElement('img');
        previewImg.src = '';
        previewImg.alt = '';
        preview.appendChild(previewImg);
        document.body.appendChild(preview);

        document.addEventListener('mouseover', function (e) {
            var wrap = e.target.closest ? e.target.closest('.order-img-wrap') : null;
            if (!wrap) return;
            var img = wrap.querySelector('img.order-img');
            if (!img || !img.src) return;
            previewImg.src = img.src;
            var rect = wrap.getBoundingClientRect();
            var top = rect.top + rect.height / 2 - 110;
            var left = rect.right + 12;
            if (left + 230 > window.innerWidth) left = rect.left - 232;
            if (top + 220 > window.innerHeight) top = window.innerHeight - 224;
            if (top < 4) top = 4;
            preview.style.transformOrigin = (left < rect.left ? 'right' : 'left') + ' center';
            preview.style.top = top + 'px';
            preview.style.left = left + 'px';
            preview.classList.add('visible');
        }, false);

        document.addEventListener('mouseout', function (e) {
            var wrap = e.target.closest ? e.target.closest('.order-img-wrap') : null;
            if (!wrap) return;
            var related = e.relatedTarget;
            if (related && wrap.contains(related)) return;
            preview.classList.remove('visible');
        }, false);
    }
});
