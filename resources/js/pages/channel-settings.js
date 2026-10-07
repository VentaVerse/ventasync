import { initLazadaExplorer } from './lazada-explorer';

function initTabs(root) {
    const prefix = root.getAttribute('data-tab-prefix') || '';
    const storageKey = root.getAttribute('data-tab-storage-key') || '';
    const fallback = root.getAttribute('data-default-tab') || '';
    const triggers = Array.from(root.querySelectorAll('[data-cs-tab]'));
    if (!triggers.length) return;

    const ids = triggers.map((t) => t.getAttribute('data-cs-tab'));

    function show(id) {
        if (ids.indexOf(id) === -1) return;
        triggers.forEach((t) => {
            const on = t.getAttribute('data-cs-tab') === id;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        ids.forEach((panelId) => {
            const panel = document.getElementById(panelId);
            if (panel) panel.hidden = panelId !== id;
        });
    }

    function writeToUrl(id) {
        if (typeof window.history?.replaceState !== 'function') return;

        const short = prefix && id.startsWith(prefix) ? id.slice(prefix.length) : id;
        const url = new URL(window.location.href);

        if (url.searchParams.get('tab') === short) return;

        url.searchParams.set('tab', short);
        window.history.replaceState(window.history.state, '', url);
    }

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', function () {
            const id = trigger.getAttribute('data-cs-tab');
            show(id);
            writeToUrl(id);
            if (storageKey) {
                try { window.localStorage.setItem(storageKey, id); } catch (e) { }
            }
        });
    });

    window.addEventListener('popstate', function () {
        const tab = new URLSearchParams(window.location.search).get('tab');
        if (tab) show(prefix + tab);
    });

    const urlTab = new URLSearchParams(window.location.search).get('tab');
    let wanted = urlTab ? prefix + urlTab : null;

    if (!wanted && storageKey) {
        try { wanted = window.localStorage.getItem(storageKey); } catch (e) { wanted = null; }
    }
    if (!wanted) wanted = fallback;

    if (wanted && ids.indexOf(wanted) !== -1) {
        show(wanted);
        writeToUrl(wanted);
    }
}

function initEnvironment(root) {
    const form = root.querySelector('[data-cs-env-form]');
    if (!form) return;

    const hidden = form.querySelector('[data-cs-env-input]');
    const options = Array.from(root.querySelectorAll('[data-cs-env-option]'));
    if (!hidden || !options.length) return;

    options.forEach((option) => {
        option.addEventListener('click', function () {
            const mode = option.getAttribute('data-cs-env-option');
            if (!mode || hidden.value === mode) return;

            hidden.value = mode;

            options.forEach((o) => {
                const on = o.getAttribute('data-cs-env-option') === mode;
                o.classList.toggle('is-active', on);
                o.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            root.querySelectorAll('[data-cs-env-panel]').forEach((panel) => {
                panel.hidden = panel.getAttribute('data-cs-env-panel') !== mode;
            });

            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });
    });
}

const SAMPLE_DATA = {
    '/api/v2/product/get_item_list': function () {
        const now = Math.floor(Date.now() / 1000);
        return {
            offset: '0',
            page_size: '50',
            update_time_from: String(now - 15 * 86400),
            update_time_to: String(now),
            item_status: 'NORMAL',
        };
    },
    '/api/v2/order/get_order_list': function () {
        const now = Math.floor(Date.now() / 1000);
        return {
            order_status: 'READY_TO_SHIP',
            time_range_field: 'create_time',
            time_from: String(now - 15 * 86400),
            time_to: String(now),
            page_size: '20',
        };
    },
    '/api/v2/product/get_item_base_info': function () { return { item_id_list: '123456' }; },
    '/api/v2/product/get_model_list': function () { return { item_id: '123456' }; },
    '/api/v2/product/get_category': function () { return { language: 'en' }; },
    '/api/v2/product/get_brand_list': function () { return { category_id: '100001', offset: '0', page_size: '100', status: '1' }; },
    '/api/v2/product/get_attribute_tree': function () { return { category_id: '100001', language: 'en' }; },
    '/api/v2/product/category_recommend': function () { return { item_name: 'Guitar Pedal' }; },
    '/api/v2/returns/get_return_list': function () { return { page_no: '1', page_size: '20' }; },
};

function safeParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
}

function initExplorer(root) {
    const panel = root.querySelector('[data-cs-explorer]');
    if (!panel) return;

    const methodSel = panel.querySelector('[data-cs-ex-method]');
    const authBox = panel.querySelector('[data-cs-ex-auth]');
    const shopBox = panel.querySelector('[data-cs-ex-shop]');
    const pathInput = panel.querySelector('[data-cs-ex-path]');
    const paramsBox = panel.querySelector('[data-cs-ex-params]');
    const hiddenParams = panel.querySelector('[data-cs-ex-hidden]');
    const kvBody = panel.querySelector('[data-cs-ex-kv]');
    const nameEl = panel.querySelector('[data-cs-ex-name]');
    const descEl = panel.querySelector('[data-cs-ex-desc]');
    const search = panel.querySelector('[data-cs-ex-search]');
    const addRow = panel.querySelector('[data-cs-ex-add]');
    const syncBtn = panel.querySelector('[data-cs-ex-sync]');
    const sampleBtn = panel.querySelector('[data-cs-ex-sample]');
    const form = panel.querySelector('[data-cs-ex-form]');
    const endpoints = Array.from(panel.querySelectorAll('[data-ep]'));

    if (!kvBody || !paramsBox) return;

    function clearRows() {
        while (kvBody.firstChild) kvBody.removeChild(kvBody.firstChild);
    }

    function tableToObject() {
        const out = {};
        Array.from(kvBody.querySelectorAll('tr')).forEach((tr) => {
            const keyEl = tr.querySelector('[data-cs-ex-key]');
            const valEl = tr.querySelector('[data-cs-ex-val]');
            const key = keyEl ? String(keyEl.value || '').trim() : '';
            if (!key) return;
            const raw = valEl ? valEl.value : '';
            const parsed = safeParse(raw);
            out[key] = parsed !== null ? parsed : raw;
        });
        return out;
    }

    function syncToJson() {
        paramsBox.value = JSON.stringify(tableToObject(), null, 2);
        if (hiddenParams) hiddenParams.value = paramsBox.value;
    }

    function buildRow(key, value) {
        const tr = document.createElement('tr');

        const tdKey = document.createElement('td');
        const keyInput = document.createElement('input');
        keyInput.className = 'x-input';
        keyInput.placeholder = 'name';
        keyInput.setAttribute('aria-label', 'Parameter name');
        keyInput.setAttribute('data-cs-ex-key', '');
        keyInput.value = key || '';
        tdKey.appendChild(keyInput);

        const tdVal = document.createElement('td');
        const valInput = document.createElement('input');
        valInput.className = 'x-input';
        valInput.placeholder = 'value';
        valInput.setAttribute('aria-label', 'Parameter value');
        valInput.setAttribute('data-cs-ex-val', '');
        valInput.value = (value === null || typeof value === 'undefined') ? '' : String(value);
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
        const obj = safeParse(paramsBox.value || '');
        if (!obj || typeof obj !== 'object') return;
        clearRows();
        Object.keys(obj).forEach((key) => {
            const value = obj[key];
            buildRow(key, typeof value === 'object' ? JSON.stringify(value) : value);
        });
    }

    function select(ep, trigger) {
        if (methodSel) methodSel.value = (ep.method || 'GET').toUpperCase();
        if (authBox) authBox.checked = ep.auth !== false;
        if (shopBox) shopBox.checked = ep.shop !== false;
        if (pathInput) pathInput.value = ep.path || '';
        if (nameEl) nameEl.textContent = ep.name || 'Selected';
        if (descEl) descEl.textContent = ep.desc || '';
        endpoints.forEach((b) => b.classList.toggle('is-selected', b === trigger));
        clearRows();
        (ep.params || []).forEach((p) => buildRow(p.k, p.ph || ''));
        syncToJson();
    }

    endpoints.forEach((button) => {
        button.addEventListener('click', function () {
            select(safeParse(button.getAttribute('data-ep') || '{}') || {}, button);
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

    if (addRow) addRow.addEventListener('click', function () { buildRow('', ''); });

    if (syncBtn) {
        syncBtn.addEventListener('click', function () {
            rebuildFromJson();
            syncToJson();
        });
    }

    if (sampleBtn) {
        sampleBtn.addEventListener('click', function () {
            const path = pathInput ? pathInput.value.trim() : '';
            const build = SAMPLE_DATA[path];
            if (!build) {
                if (typeof window.showFlashError === 'function') {
                    window.showFlashError('No sample values for ' + path + '. Pick an endpoint from the list first.');
                }
                return;
            }
            const data = build();
            clearRows();
            Object.keys(data).forEach((key) => buildRow(key, data[key]));
            syncToJson();
        });
    }

    kvBody.addEventListener('input', syncToJson);

    if (form) form.addEventListener('submit', syncToJson);
}

function initSelectOnClick(root) {
    root.querySelectorAll('[data-select-on-click]').forEach((input) => {
        input.addEventListener('click', function () { input.select(); });
        input.addEventListener('focus', function () { input.select(); });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-channel-settings]');
    if (!root) return;

    initTabs(root);
    initEnvironment(root);
    initSelectOnClick(root);

    initExplorer(root);
    initLazadaExplorer(root);
});
