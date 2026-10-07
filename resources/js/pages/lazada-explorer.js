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

export function initLazadaExplorer(root) {
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
