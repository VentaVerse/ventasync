/*
 * Channel settings page (Shopee first, Lazada second; TikTok has the same five
 * tabs and binds the same attributes).
 *
 * The tab strip, the environment switch, the API-logging toggle and the
 * select-on-click redirect field are the same object on every channel and are
 * shared here. The API explorer is not: each marketplace signs and shapes its
 * requests differently and posts different field names, so each channel's
 * explorer is its own function guarding on its own attribute. Shopee's is
 * initExplorer below; Lazada's is ./lazada-explorer.js.
 *
 * Ports, unchanged in behaviour, the four inline scripts that used to sit at
 * the bottom of extensions/shopee/views/index.blade.php: tab switching, the
 * environment switch, the API-logging toggle, and the API explorer. That view
 * has been rebuilt on the design tokens and left the .x-legacy boundary, and
 * this project does not put behaviour in Blade.
 *
 * Nothing here touches an OAuth surface. The authorize link, the auth-code
 * exchange form and the token-refresh form are plain server-rendered controls
 * with no JS attached, exactly as before, because their URLs and hidden
 * fields are contracts registered at the Shopee Open Platform.
 *
 * Deliberate changes from the inline originals, all reported:
 *
 *   1. Every URL, storage key and default now comes off a data- attribute
 *      instead of being Blade-interpolated into a script literal, which is
 *      what lets one module serve three channels.
 *   2. The environment switch and the logging toggle no longer write inline
 *      style strings (background, left, colour) to move a knob and repaint a
 *      track. State is a class or an attribute and the stylesheet draws it.
 *   3. The dead `spCopy()` helper is gone. It was defined in that inline
 *      block and called by nothing on the page.
 *
 * The wire is unchanged: the same hidden `mode` field posted to toggle-mode,
 * the same JSON body and X-CSRF-TOKEN header on toggle-logging, the same
 * method / api_path / use_access_token / use_shop_id / params_json fields on
 * the explorer form, and the same `?tab=` URL contract the controller
 * redirects with after saving logs settings.
 */

import { initLazadaExplorer } from './lazada-explorer';

/* ------------------------------------------------------------------
   Tabs
   ------------------------------------------------------------------ */

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

    /*
     * The URL names the tab you are looking at.
     *
     * ?tab= was already read on load - the controllers redirect with
     * ?tab=logs after saving log settings - but clicking a tab only ever
     * wrote to localStorage, so the address bar went on describing the tab
     * the page opened with. A tab could not be linked to, bookmarked, or
     * survive a refresh, and sending someone "the API explorer" meant sending
     * a URL that would open on Connection and telling them where to click.
     *
     * replaceState rather than pushState, deliberately. Each tab has its own
     * address and can be copied out of the bar at any moment, but stepping
     * through five tabs does not bury the page the operator arrived from
     * under five history entries they have to press Back through to leave.
     *
     * Every other query parameter is preserved: a multi-store channel carries
     * its store in the same URL, and dropping it here would move the operator
     * to a different shop.
     */
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
                try { window.localStorage.setItem(storageKey, id); } catch (e) { /* private mode */ }
            }
        });
    });

    // A real back or forward - arriving from a link, or the operator editing
    // the address - re-reads the URL rather than leaving the panels showing
    // whatever was open before.
    window.addEventListener('popstate', function () {
        const tab = new URLSearchParams(window.location.search).get('tab');
        if (tab) show(prefix + tab);
    });

    // Restore order, unchanged from the inline original: an explicit ?tab=
    // in the URL wins (the controller redirects with ?tab=logs after saving
    // log settings), then whatever the operator last chose, then the page's
    // own default when a result is waiting to be read.
    const urlTab = new URLSearchParams(window.location.search).get('tab');
    let wanted = urlTab ? prefix + urlTab : null;

    if (!wanted && storageKey) {
        try { wanted = window.localStorage.getItem(storageKey); } catch (e) { wanted = null; }
    }
    if (!wanted) wanted = fallback;

    if (wanted && ids.indexOf(wanted) !== -1) {
        show(wanted);
        // The address describes what is on screen from the first paint, so a
        // tab restored from localStorage is as copyable as one that was
        // clicked. Without this the panel and the URL disagree the moment the
        // page opens on a remembered tab.
        writeToUrl(wanted);
    }
}

/* ------------------------------------------------------------------
   Environment switch. Two named environments, not an on/off state. The
   click sets the hidden `mode` field, reveals the matching panel, and
   submits the form, which is exactly what the old toggle did.
   ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------
   API logging toggle. Saves without a page reload, and puts the switch
   back if the request never lands - the original did the same, it just
   repainted the knob with inline styles rather than letting the
   checkbox's own :checked state drive it.
   ------------------------------------------------------------------ */

function initLoggingToggle(root) {
    const box = root.querySelector('[data-cs-logging]');
    if (!box) return;

    const url = box.getAttribute('data-url');
    const label = root.querySelector('[data-cs-logging-label]');
    const token = document.querySelector('meta[name=csrf-token]');
    if (!url || !token) return;

    // The three marketplace channels' endpoints read `api_logging`;
    // Pedallion's column and its controller both say `logging_enabled`. The
    // field name is the wire, so it is declared by the page rather than
    // assumed here, and it defaults to what the first three channels send so
    // none of their markup changes.
    const field = box.getAttribute('data-field') || 'api_logging';

    function paint(enabled) {
        if (label) label.textContent = enabled ? 'Recording' : 'Not recording';
    }

    box.addEventListener('change', function () {
        const enabled = box.checked;
        paint(enabled);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token.content,
                Accept: 'application/json',
            },
            body: JSON.stringify({ [field]: enabled ? 1 : 0 }),
        })
            .then(function (response) {
                if (!response.ok) throw new Error('save failed');
            })
            .catch(function () {
                box.checked = !enabled;
                paint(!enabled);
                if (typeof window.showFlashError === 'function') {
                    window.showFlashError('Could not save that. Check the connection and try again.');
                }
            });
    });
}

/* ------------------------------------------------------------------
   API explorer. Same behaviour as the inline original: picking an
   endpoint fills method / auth / shop / path and seeds the parameter
   table, the table and the JSON box stay in step, and the search box
   filters the catalogue.
   ------------------------------------------------------------------ */

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
    // Optional second field. Shopee posts one textarea named `params_json` and
    // has none of these; TikTok's controller validates `params_json` but the
    // page shows the operator a readable `params_json_pretty` textarea, so the
    // hidden field is kept in step with it. Absent on Shopee, so every write
    // below is guarded.
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

/* ------------------------------------------------------------------
   Select-on-click for the readonly redirect URI. It exists to be copied
   into the Shopee console, so one click should select the whole string.
   Replaces an inline onclick attribute, which needs 'unsafe-inline' in a
   script-src and puts behaviour back in the Blade.
   ------------------------------------------------------------------ */

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
    initLoggingToggle(root);
    initSelectOnClick(root);

    // The explorer is the one section of this page that is genuinely per
    // channel, because each marketplace signs and shapes its requests
    // differently and the field names posted differ with them. Each variant
    // guards on its own attribute, so exactly one binds per page and neither
    // touches the other's markup. Shopee's initExplorer above looks for
    // [data-cs-explorer]; Lazada's looks for [data-lz-explorer].
    initExplorer(root);
    initLazadaExplorer(root);
});
