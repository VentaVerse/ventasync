document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('tiktok-orders-page');
    var detailPage = document.getElementById('tiktok-order-detail-page');
    if (!page && !detailPage) return;

    if (page) {
        openPendingAwb(page);
        initFetchUpdateOverlay();
        initTrackingModal();
        initShipModal();
    }

    if (detailPage) {
        initTrackingModal();
        initBrokenImages(detailPage);
    }

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

    function openPendingAwb(root) {
        var raw = root.getAttribute('data-awb-url') || '';
        if (!raw) return;

        var resolved;
        try {
            resolved = new URL(raw, window.location.origin);
        } catch (e) {
            return;
        }
        if (resolved.origin !== window.location.origin) return;

        window.open(resolved.href, '_blank', 'noopener');
    }

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
            if (e.defaultPrevented) return;

            var isUpdate = clickedBtn === 'update';

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

    function fillParcel(container, key, hiddenClass) {
        if (!container) return;
        while (container.firstChild) container.removeChild(container.firstChild);
        var tpl = key ? document.querySelector('template[data-parcel-for="' + CSS.escape(String(key)) + '"]') : null;
        if (!tpl || !tpl.content || !tpl.content.firstElementChild) {
            container.classList.add(hiddenClass);
            return;
        }
        container.appendChild(tpl.content.cloneNode(true));
        container.classList.remove(hiddenClass);
    }

    function initShipModal() {
        var modal = document.getElementById('ttShipModal');
        var confirmBtn = document.getElementById('btnTtShipConfirm');
        var orderNoEl = document.getElementById('ttShipOrderNo');
        var parcelEl = document.getElementById('ttShipParcel');
        if (!modal || !confirmBtn) return;

        var currentForm = null;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseTtShip'),
            document.getElementById('btnCloseTtShip2')
        ]);

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnTtShip') : null;
            if (!btn) return;
            e.preventDefault();
            currentForm = btn.closest('form');
            if (orderNoEl) orderNoEl.textContent = btn.getAttribute('data-order-no') || '';
            fillParcel(parcelEl, btn.getAttribute('data-order-id'), 'tt-hidden');
            confirmBtn.disabled = false;
            showModal(modal);
        });

        confirmBtn.addEventListener('click', function () {
            if (!currentForm) return;
            confirmBtn.disabled = true;
            hideModal(modal);
            if (typeof currentForm.requestSubmit === 'function') {
                currentForm.requestSubmit();
            } else {
                currentForm.submit();
            }
        });
    }

    function initTrackingModal() {
        var modal = document.getElementById('ttTrackingModal');
        var body = document.getElementById('ttTrackingBody');
        var sub = document.getElementById('ttTrackingSub');
        if (!modal || !body) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseTtTracking'),
            document.getElementById('btnCloseTtTracking2')
        ]);

        // TikTok sends Unix timestamps in seconds on some fields and milliseconds on others.
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
                    clear(body);
                    if (sub) sub.textContent = '';
                    body.appendChild(textNode('div', 'text-danger', 'Could not load tracking for this order.'));
                });
        }

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

    function initBrokenImages(root) {
        root.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
            img.hidden = true;
        }, true);
    }
});
