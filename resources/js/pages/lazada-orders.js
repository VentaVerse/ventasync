/**
 * Lazada Orders and Lazada Returns pages
 * (extensions/lazada/views/orders/index.blade.php and
 * extensions/lazada/views/orders/returns.blade.php).
 *
 * This used to be two inline <script> blocks at the bottom of the Orders
 * Blade file. It moves here as part of that page's exit from the .x-legacy
 * boundary: a new-UX page owns no inline script, the same way it owns no
 * inline style. Bundled into the shared app.js (every layout loads it) and
 * guarded on #lazada-orders-page and, separately, on #lazada-returns-page,
 * so it is inert everywhere else. That is the same convention
 * shopee-orders.js, orders-filter.js and confirm-modal.js already use.
 *
 * Behaviour is unchanged from the inline version: same endpoints, same POST
 * fields, same overlay and modal flow, same window.showFlashError calls
 * (defined by legacy-flash.js, also bundled into app.js so both are
 * guaranteed present together).
 *
 * Four things changed while moving, all of them defects rather than design:
 *
 *   1. The logistics trace table was assembled as an HTML string from data
 *      returned by Lazada's own API and written in with innerHTML. The
 *      inline version hand-rolled an esc() function to make that safe, which
 *      is a hand-rolled escaper standing between a third-party payload and
 *      the DOM. Every node is now built with createElement/textContent, so
 *      there is no escaping to get wrong and no code path in this module
 *      that writes markup into the DOM as a string.
 *
 *   2. The Blade file interpolated three route templates and two session
 *      values straight into the script with @json. Those now ride on the
 *      page root as data attributes, escaped by Blade.
 *
 *   3. session('lazada_awb_url') was handed to window.open() unchecked. It
 *      is written by the controller from its own routes, but a value that
 *      reaches window.open() should not be trusted on provenance alone, so
 *      it is now resolved against the page's own origin and dropped if it
 *      points anywhere else.
 *
 *   4. Failure paths. The trace fetch left the modal saying "Loading" if the
 *      request threw before its own catch could run, and the fetch form
 *      showed its overlay before validating the dates on some paths. Every
 *      branch below now ends with the overlay hidden or the body showing a
 *      real message, and nothing is disabled without a path that re-enables
 *      it.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('lazada-orders-page');
    var returnsPage = document.getElementById('lazada-returns-page');
    var detailPage = document.getElementById('lazada-order-detail-page');

    if (page) {
        openPendingAwb(page);
        initFetchUpdateOverlay();
        initPackPrintModal(page);
        initBulkPackModal();
        initLogisticsModal();
        initImagePreview();
    }

    // The order detail page (extensions/lazada/views/orders/show.blade.php)
    // is a third document with a third root id, not a section of the list.
    // It carries the same tracking modal - one copy of that markup lives in
    // orders/_modals.blade.php and both pages include it - and its Tracking
    // button is the same .btnLzLogistics contract, so the flow is the
    // module's existing one rather than a second implementation.
    //
    // It has no fetch/update command bar and no pack-print follow-up, so
    // neither initialiser is called here. initImagePreview() is deliberately
    // not called either: this page's thumbnails are already the 96px the
    // list's preview zooms up to, which is what
    // `.od-page .order-img-wrap { cursor: default }` already states.
    if (detailPage) {
        initLogisticsModal();
        initBrokenImages(detailPage);
    }

    // Returns is its own page, not a section of Orders, so it gets its own
    // root id and wires up only what it actually has: the fetch overlay and
    // the item-thumbnail hover preview.
    if (returnsPage) {
        initFetchReturnsOverlay();
        initImagePreview();
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

    // Closes a modal on its backdrop, its close button, and Escape. Three
    // separate modals wanted the same three behaviours; wiring them once is
    // what stops one of them quietly losing its Escape handler later.
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
       A waybill generated by the request that just finished is handed back
       through the session and opened once, here.
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
       Loading overlay on Fetch / Update (Orders page).
       ------------------------------------------------------------------ */
    function initFetchUpdateOverlay() {
        var overlay = document.getElementById('lzLoadingOverlay');
        var title = document.getElementById('lzLoadingTitle');
        var fetchForm = document.getElementById('formFetchLazadaOrders');
        if (!fetchForm) return;

        var clickedBtn = null;
        var fetchBtn = document.getElementById('btnFetchOrders');
        var updateBtn = document.getElementById('btnUpdateOrders');

        if (fetchBtn) fetchBtn.addEventListener('click', function () { clickedBtn = 'fetch'; });
        if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

        fetchForm.addEventListener('submit', function (e) {
            var dateFrom = fetchForm.querySelector('input[name="date_from"]');
            var dateTo = fetchForm.querySelector('input[name="date_to"]');

            // Validate before anything is shown, so a rejected submit never
            // leaves an overlay covering the page it refused to leave.
            if (!dateFrom || !dateTo || !dateFrom.value || !dateTo.value) {
                e.preventDefault();
                hideModal(overlay);
                if (window.showFlashError) {
                    window.showFlashError('Please provide both From and To dates.');
                }
                return;
            }

            if (title) {
                title.textContent = (clickedBtn === 'update') ? 'Updating orders' : 'Fetching orders';
            }
            showModal(overlay);
        });
    }

    /* ------------------------------------------------------------------
       Loading overlay on Fetch returns (Returns page). Same
       #lzLoadingOverlay / #lzLoadingTitle contract as the Orders page's own
       overlay, just a separate copy of the modal markup because the two
       pages are separate documents.
       ------------------------------------------------------------------ */
    function initFetchReturnsOverlay() {
        var overlay = document.getElementById('lzLoadingOverlay');
        var title = document.getElementById('lzLoadingTitle');
        var form = document.getElementById('formFetchLazadaReturns');
        if (!form || !overlay) return;

        form.addEventListener('submit', function () {
            if (title) title.textContent = 'Fetching returns';
            showModal(overlay);
        });
    }

    /* ------------------------------------------------------------------
       Pack and print follow-up modal. The controller redirects back with
       the packed order's id in the session; the page root carries it, along
       with the three route templates whose only variable part is that id.
       ------------------------------------------------------------------ */
    function initPackPrintModal(root) {
        var modal = document.getElementById('lzPackPrintModal');
        if (!modal) return;

        var orderEl = document.getElementById('lzPackPrintOrder');
        var linkPrintOnly = document.getElementById('linkPrintOnly');
        var formShipPrint = document.getElementById('formShipPrint');
        var formRecreate = document.getElementById('formRecreatePackage');

        bindModalDismiss(modal, [document.getElementById('btnClosePackPrint')]);

        var orderId = root.getAttribute('data-pack-print-order') || '';
        if (!orderId) return;

        var fill = function (el, attr, template) {
            if (!el || !template) return;
            el.setAttribute(attr, template.replace('__OID__', encodeURIComponent(orderId)));
        };

        fill(linkPrintOnly, 'href', root.getAttribute('data-awb-template'));
        fill(formShipPrint, 'action', root.getAttribute('data-ship-print-template'));
        fill(formRecreate, 'action', root.getAttribute('data-recreate-template'));

        if (orderEl) orderEl.textContent = orderId;
        showModal(modal);
    }

    /* ------------------------------------------------------------------
       Bulk pack result. Rendered by the server when a selection was packed,
       so its presence in the document IS the signal - there is no data
       attribute to read and nothing to fetch. Every outcome is already in
       the markup; this only opens it and wires the dismiss.
       ------------------------------------------------------------------ */
    function initBulkPackModal() {
        var modal = document.getElementById('lzBulkPackModal');
        if (!modal) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseBulkPack'),
            document.getElementById('btnBulkPackDone')
        ]);

        showModal(modal);
    }

    /* ------------------------------------------------------------------
       Tracking modal: Lazada's logistics trace, which arrives in one of
       three shapes depending on which endpoint answered.
       ------------------------------------------------------------------ */
    function initLogisticsModal() {
        var modal = document.getElementById('lzLogisticsModal');
        var body = document.getElementById('lzLogisticsBody');
        var sub = document.getElementById('lzLogisticsSub');
        if (!modal || !body) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseLogistics'),
            document.getElementById('btnLogisticsClose2')
        ]);

        function formatEventTime(t) {
            if (!t) return '';
            if (typeof t === 'number' && t > 1000000000000) {
                var d = new Date(t);
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
                    + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
            }
            if (typeof t === 'number' && t > 1000000000) {
                return formatEventTime(t * 1000);
            }
            return String(t);
        }

        // Lazada answers with traces under any of three shapes depending on
        // which of its endpoints served the request. All three are tried, in
        // the same order the inline script tried them.
        function collectTraces(payload) {
            var data = (payload && payload.data) ? payload.data : payload;
            var traces = [];
            if (!data) return traces;

            var direct = data.traces || data.trace_list || data.traceList || data.events || [];
            if (Array.isArray(direct)) traces = traces.concat(direct);

            if (traces.length === 0 && Array.isArray(data.packages)) {
                data.packages.forEach(function (pkg) {
                    var list = pkg.package_trace_list || pkg.traceList || [];
                    if (Array.isArray(list)) traces = traces.concat(list);
                });
            }

            if (traces.length === 0 && data.result && Array.isArray(data.result.module)) {
                data.result.module.forEach(function (mod) {
                    (mod.package_detail_info_list || []).forEach(function (pkg) {
                        var events = pkg.logistic_detail_info_list || [];
                        if (Array.isArray(events)) traces = traces.concat(events);
                    });
                });
            }

            return traces;
        }

        function renderTraces(traces) {
            clear(body);

            if (traces.length === 0) {
                body.appendChild(textNode('div', 'text-secondary', 'Lazada returned no tracking events for this order.'));
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'lz-trace';

            traces.forEach(function (t) {
                var row = document.createElement('div');
                row.className = 'lz-trace__event';
                row.appendChild(textNode('div', 'lz-trace__time', formatEventTime(t.event_time || t.time || t.timestamp || t.update_time || '')));
                row.appendChild(textNode('div', 'lz-trace__status', t.title || t.status || t.event || t.action || ''));

                var detail = t.description || t.desc || t.detail || t.message || '';
                if (detail) row.appendChild(textNode('div', 'lz-trace__detail', detail));

                wrap.appendChild(row);
            });

            body.appendChild(wrap);
        }

        function loadTraces(url) {
            clear(body);
            body.appendChild(textNode('div', 'text-secondary', 'Loading'));

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                .then(function (res) {
                    if (!res.ok || !res.json || res.json.ok !== true) {
                        var j = res.json || {};
                        var msg = (j.body && j.body.message) ? j.body.message
                            : (j.message ? j.message : 'Lazada could not be reached for this order.');
                        clear(body);
                        body.appendChild(textNode('div', 'text-danger', msg));
                        return;
                    }
                    renderTraces(collectTraces(res.json.body));
                })
                .catch(function () {
                    // Covers both a network failure and a response that is
                    // not JSON at all, either of which used to leave the
                    // modal reading "Loading" forever.
                    clear(body);
                    body.appendChild(textNode('div', 'text-danger', 'Could not load tracking for this order.'));
                });
        }

        // Delegated, so it keeps working for rows added by anything later.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnLzLogistics') : null;
            if (!btn) return;
            var url = btn.getAttribute('data-url');
            if (!url) return;
            e.preventDefault();
            if (sub) sub.textContent = '';
            showModal(modal);
            loadTraces(url);
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
