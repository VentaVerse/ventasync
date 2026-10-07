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
    }

    if (detailPage) {
        initLogisticsModal();
        initBrokenImages(detailPage);
    }

    if (returnsPage) {
        initFetchReturnsOverlay();
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
            if (e.defaultPrevented) return;

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

            if (title) {
                title.textContent = (clickedBtn === 'update') ? 'Updating orders' : 'Fetching orders';
            }
            showModal(overlay);
        });
    }

    function initFetchReturnsOverlay() {
        var overlay = document.getElementById('lzLoadingOverlay');
        var title = document.getElementById('lzLoadingTitle');
        var form = document.getElementById('formFetchLazadaReturns');
        if (!form || !overlay) return;

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;

            if (title) title.textContent = 'Fetching returns';
            showModal(overlay);
        });
    }

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

    function initBulkPackModal() {
        var modal = document.getElementById('lzBulkPackModal');
        if (!modal) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseBulkPack'),
            document.getElementById('btnBulkPackDone')
        ]);

        showModal(modal);
    }

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

        // Lazada returns traces under any of three shapes depending on which endpoint answered; try all.
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
                    clear(body);
                    body.appendChild(textNode('div', 'text-danger', 'Could not load tracking for this order.'));
                });
        }

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

    function initBrokenImages(root) {
        root.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
            img.hidden = true;
        }, true);
    }
});
