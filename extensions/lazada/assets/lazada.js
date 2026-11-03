{
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
}

{
const { initChannelListings } = window.VentaSync;

document.addEventListener('DOMContentLoaded', function () {
    initChannelListings({
        overlaySelector: '#lazada-listings-progress',
        bulkField: 'product_ids[]',
        slowTitle: 'Talking to Lazada',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' listing' : ' listings'),
        emptyMessage: 'Select at least one listing first.',
    });
});
}

{
function safeJsonParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
}

function isObject(value) {
    return value !== null && typeof value === 'object';
}

// Lazada's console hands out payloads with literal \n and \" escapes; unescape before showing.
function tryParsePayloadString(value) {
    if (typeof value !== 'string') return null;

    const parsed = safeJsonParse(value);
    if (parsed !== null) return { parsed: parsed, normalized: value };

    const looksEscaped = value.indexOf('\\n') >= 0
        || value.indexOf('\\t') >= 0
        || value.indexOf('\\r') >= 0
        || value.indexOf('\\"') >= 0
        || value.indexOf('\\\\') >= 0;

    if (looksEscaped) {
        const unescaped = value
            .replace(/\\r/g, '\r')
            .replace(/\\n/g, '\n')
            .replace(/\\t/g, '\t')
            .replace(/\\"/g, '"')
            .replace(/\\\\/g, '\\');
        const parsedAgain = safeJsonParse(unescaped);
        if (parsedAgain !== null) return { parsed: parsedAgain, normalized: unescaped };
    }

    return null;
}

function buildPrettyAndHidden(source) {
    const pretty = JSON.parse(JSON.stringify(source || {}));
    const hidden = JSON.parse(JSON.stringify(source || {}));

    if (source && Object.prototype.hasOwnProperty.call(source, 'payload')) {
        const payload = source.payload;

        if (isObject(payload) || Array.isArray(payload)) {
            hidden.payload = JSON.stringify(payload, null, 2);
            pretty.payload = payload;
            return { pretty: pretty, hidden: hidden };
        }

        if (typeof payload === 'string') {
            const attempt = tryParsePayloadString(payload);
            if (attempt && attempt.parsed !== null && (isObject(attempt.parsed) || Array.isArray(attempt.parsed))) {
                pretty.payload = attempt.parsed;
                hidden.payload = attempt.normalized;
                return { pretty: pretty, hidden: hidden };
            }
        }
    }

    return { pretty: pretty, hidden: hidden };
}

function samplePayloadFor(path) {
    if (path === '/product/create' || path === '/product/update') {
        return JSON.stringify({
            Request: {
                Product: {
                    PrimaryCategory: 0,
                    Images: { Image: ['https://<your-domain>/path/to/image.jpg'] },
                    Attributes: {
                        name: 'Sample Product Name',
                        description: 'Sample description',
                        brand: 'No Brand',
                        model: 'MODEL-001',
                        package_height: '10',
                        package_length: '10',
                        package_width: '10',
                        package_weight: '1',
                    },
                    Skus: { Sku: [{ SellerSku: 'SAMPLE-SKU', price: 100, quantity: 1 }] },
                },
            },
        }, null, 2);
    }
    if (path === '/images/migrate') {
        return JSON.stringify({ Request: { Images: { Url: ['https://<your-domain>/path/to/image1.jpg'] } } }, null, 2);
    }
    if (path === '/image/migrate') {
        return JSON.stringify({ Request: { Image: { Url: 'https://<your-domain>/path/to/image1.jpg' } } }, null, 2);
    }
    return '{\n  "Request": {}\n}';
}

const SAMPLE_SELLER_SKU_LIST = '["test00111","test00222","test00333"]';
const SAMPLE_SKU_ID_LIST = '["SkuId_1269656765_5230534246","SkuId_1269656765_5230534247","SkuId_1269656765_5230534248"]';

function initLazadaExplorer(root) {
    const panel = root.querySelector('[data-lz-explorer]');
    if (!panel) return;

    const methodSel = panel.querySelector('[data-lz-ex-method]');
    const authBox = panel.querySelector('[data-lz-ex-auth]');
    const pathInput = panel.querySelector('[data-lz-ex-path]');
    const prettyBox = panel.querySelector('[data-lz-ex-pretty]');
    const hiddenBox = panel.querySelector('[data-lz-ex-hidden]');
    const kvBody = panel.querySelector('[data-lz-ex-kv]');
    const nameEl = panel.querySelector('[data-lz-ex-name]');
    const descEl = panel.querySelector('[data-lz-ex-desc]');
    const search = panel.querySelector('[data-lz-ex-search]');
    const addRow = panel.querySelector('[data-lz-ex-add]');
    const syncBtn = panel.querySelector('[data-lz-ex-sync]');
    const sampleBtn = panel.querySelector('[data-lz-ex-sample]');
    const form = panel.querySelector('[data-lz-ex-form]');
    const endpoints = Array.from(panel.querySelectorAll('[data-ep]'));

    if (!kvBody || !prettyBox) return;

    let lastEdited = 'table';

    function clearRows() {
        while (kvBody.firstChild) kvBody.removeChild(kvBody.firstChild);
    }

    function tableToObject() {
        const out = {};
        Array.from(kvBody.querySelectorAll('tr')).forEach((tr) => {
            const keyEl = tr.querySelector('[data-lz-ex-key]');
            const valEl = tr.querySelector('[data-lz-ex-val]');
            const key = keyEl ? String(keyEl.value || '').trim() : '';
            if (!key) return;

            const raw = valEl ? valEl.value : '';
            if (key.toLowerCase() === 'payload') {
                out[key] = raw;
                return;
            }
            const parsed = safeJsonParse(raw);
            out[key] = parsed !== null ? parsed : raw;
        });
        return out;
    }

    function writeBoth(source) {
        const both = buildPrettyAndHidden(source);
        prettyBox.value = JSON.stringify(both.pretty, null, 2);
        if (hiddenBox) hiddenBox.value = JSON.stringify(both.hidden, null, 2);
        return both;
    }

    function syncToJson() {
        writeBoth(tableToObject());
    }

    function buildRow(key, value) {
        const tr = document.createElement('tr');

        const tdKey = document.createElement('td');
        const keyInput = document.createElement('input');
        keyInput.className = 'x-input';
        keyInput.placeholder = 'name';
        keyInput.setAttribute('aria-label', 'Parameter name');
        keyInput.setAttribute('data-lz-ex-key', '');
        keyInput.value = key || '';
        tdKey.appendChild(keyInput);

        const tdVal = document.createElement('td');
        let valInput;
        if (String(key || '').toLowerCase() === 'payload') {
            valInput = document.createElement('textarea');
            valInput.className = 'x-input api-code';
            valInput.rows = 6;
            valInput.placeholder = 'payload, a JSON or XML string';
            valInput.value = isObject(value) ? JSON.stringify(value, null, 2) : (value || '');
        } else {
            valInput = document.createElement('input');
            valInput.className = 'x-input';
            valInput.placeholder = 'value';
            valInput.value = (value === null || typeof value === 'undefined') ? '' : String(value);
        }
        valInput.setAttribute('aria-label', 'Parameter value');
        valInput.setAttribute('data-lz-ex-val', '');
        tdVal.appendChild(valInput);

        const tdAct = document.createElement('td');
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'x-btn x-btn--ghost x-btn--sm';
        remove.textContent = 'Remove';
        remove.addEventListener('click', function () {
            tr.remove();
            syncToJson();
        });
        tdAct.appendChild(remove);

        tr.appendChild(tdKey);
        tr.appendChild(tdVal);
        tr.appendChild(tdAct);
        kvBody.appendChild(tr);
    }

    function rebuildFromJson() {
        const parsed = safeJsonParse(prettyBox.value || '');
        if (!parsed || typeof parsed !== 'object') return;
        const both = writeBoth(parsed);
        clearRows();
        Object.keys(both.pretty).forEach((key) => buildRow(key, both.pretty[key]));
    }

    function select(ep, trigger) {
        if (methodSel) methodSel.value = (ep.method || 'GET').toUpperCase();
        if (authBox) authBox.checked = !!ep.auth;
        if (pathInput) pathInput.value = ep.path || '';
        if (nameEl) nameEl.textContent = ep.name || 'Selected';
        if (descEl) descEl.textContent = ep.desc || '';
        endpoints.forEach((b) => b.classList.toggle('is-selected', b === trigger));

        clearRows();
        (ep.params || []).forEach((p) => buildRow(p.k, p.ph || ''));

        const hasPayload = (ep.params || []).some((p) => String(p.k).toLowerCase() === 'payload');
        const hasSample = hasPayload || String(ep.path || '') === '/product/remove';
        if (sampleBtn) sampleBtn.hidden = !hasSample;

        lastEdited = 'table';
        syncToJson();
    }

    endpoints.forEach((button) => {
        button.addEventListener('click', function () {
            select(safeJsonParse(button.getAttribute('data-ep') || '{}') || {}, button);
        });
    });

    if (search) {
        search.addEventListener('input', function () {
            const query = String(search.value || '').toLowerCase();
            endpoints.forEach((button) => {
                const haystack = ((button.textContent || '') + ' ' + (button.getAttribute('data-ep') || '')).toLowerCase();
                button.hidden = haystack.indexOf(query) === -1;
            });
        });
    }

    if (addRow) {
        addRow.addEventListener('click', function () {
            buildRow('', '');
            lastEdited = 'table';
        });
    }

    if (syncBtn) {
        syncBtn.addEventListener('click', function () {
            rebuildFromJson();
            syncToJson();
            lastEdited = 'table';
        });
    }

    if (sampleBtn) {
        sampleBtn.addEventListener('click', function () {
            const path = pathInput ? String(pathInput.value || '') : '';

            Array.from(kvBody.querySelectorAll('tr')).forEach((tr) => {
                const keyEl = tr.querySelector('[data-lz-ex-key]');
                const valEl = tr.querySelector('[data-lz-ex-val]');
                if (!keyEl || !valEl) return;
                const key = String(keyEl.value || '').trim();

                if (path === '/product/remove') {
                    if (key === 'seller_sku_list') valEl.value = SAMPLE_SELLER_SKU_LIST;
                    if (key === 'sku_id_list') valEl.value = SAMPLE_SKU_ID_LIST;
                    return;
                }

                if (key.toLowerCase() === 'payload') valEl.value = samplePayloadFor(path);
            });

            lastEdited = 'table';
            syncToJson();
        });
    }

    kvBody.addEventListener('input', function (e) {
        if (!e || !e.target || !kvBody.contains(e.target)) return;
        lastEdited = 'table';
        syncToJson();
    });

    prettyBox.addEventListener('input', function () { lastEdited = 'json'; });

    if (form) {
        form.addEventListener('submit', function () {
            if (lastEdited === 'json') rebuildFromJson();
            syncToJson();
        });
    }

    const seeded = safeJsonParse(prettyBox.value || '');
    if (seeded && typeof seeded === 'object') {
        rebuildFromJson();
    }
    syncToJson();
}

window.VentaSync.initLazadaExplorer = initLazadaExplorer;
}
