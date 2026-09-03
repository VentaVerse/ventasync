/*
 * Pedallion's two pages that need behaviour: the Settings page (connection
 * test, request console, cron copy buttons) and the Reference Data page (a
 * client-side filter over the cached manufacturer list).
 *
 * One module for the channel rather than one per page, because both are small
 * and each half guards on its own root attribute, so neither runs on a page
 * that does not have it.
 *
 * Round F Task 2 rebuilt extensions/pedallion/views/index.blade.php on the
 * design tokens and took it out of the .x-legacy boundary. It carried a long
 * inline <script> at the bottom with four blocks and three global functions;
 * this project does not put behaviour in Blade, so the parts that are
 * Pedallion's own live here.
 *
 * What is NOT here, because it is shared: the tab strip, handled by
 * ./channel-settings.js, which this page opts into by carrying
 * [data-channel-settings] and the same data-cs-tab attributes. The API
 * log's recording control is the shared partial's plain POST mode form
 * (#200), so it needs no script at all.
 *
 * Everything that varies is a data- attribute on the page root, so no URL is
 * Blade-interpolated into a script literal:
 *
 *   data-test-url      POST {base_url, api_key} -> the client's own ping result
 *   data-explorer-url  POST {method, path, body} -> the client's own result
 *
 * Both are rendered empty for the view tier, so this module sends nothing at
 * all for an operator whose routes would refuse it.
 *
 * SECURITY. Every response is written with textContent, never innerHTML,
 * exactly as the inline original did. The API key typed into the connection
 * form is sent to this application's own endpoint over the same origin and is
 * never written back into the DOM.
 *
 * Deliberate changes from the inline original, all reported:
 *
 *   1. The three global window functions (pedExplorer, pedCopy, and the
 *      anonymous logging IIFE) and their onclick attributes are gone.
 *   2. The quick-endpoint buttons no longer fire the request for you. They
 *      fill the method and path and stop there, so a click on a suggestion
 *      cannot put a request on the wire before the operator has read it.
 *   3. The logging toggle no longer writes inline style strings to move a
 *      knob and repaint a track; that is a class and the stylesheet draws it.
 */

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    }).then(function (response) {
        return response.json().catch(function () {
            // A non-JSON body means the server fell over or the session
            // expired into a login page. Say so rather than throwing a
            // SyntaxError the operator cannot act on.
            return { ok: false, error: 'The server did not answer with a result. Try again, and sign in again if this keeps happening.' };
        });
    });
}

/** Disable a button and swap its label while something is in flight. */
function busy(button, label) {
    if (!button) return function () {};
    const original = button.textContent;
    button.disabled = true;
    button.textContent = label;
    return function () {
        button.disabled = false;
        button.textContent = original;
    };
}

/* ------------------------------------------------------------------
   Connection test. Sends what is typed into the form rather than what
   is stored, so a key can be checked before it is saved, which is what
   the pre-redesign page did and what a first-time setup needs.

   PedallionController::testConnection() falls back to the stored row
   when either field arrives empty, so leaving the key box blank tests
   the saved credential. That is the same branch as before.
   ------------------------------------------------------------------ */

function initTest(root, testUrl) {
    const button = root.querySelector('[data-pd-test]');
    const result = root.querySelector('[data-pd-test-result]');
    if (!button || !testUrl) return;

    button.addEventListener('click', function () {
        const form = button.closest('form');
        const baseUrl = form ? (form.querySelector('[name="base_url"]') || {}).value : '';
        const apiKey = form ? (form.querySelector('[name="api_key"]') || {}).value : '';

        const restore = busy(button, 'Testing');
        if (result) {
            result.textContent = 'Talking to Pedallion.';
            result.classList.remove('cs-actions__note--ok', 'cs-actions__note--fail');
        }

        postJson(testUrl, { base_url: baseUrl, api_key: apiKey })
            .then(function (data) {
                restore();
                if (!result) return;

                if (data && data.ok) {
                    result.textContent = 'Connected.';
                    result.classList.add('cs-actions__note--ok');
                } else {
                    const reason = (data && (data.error || (data.body && data.body.message))) || 'no reason given';
                    result.textContent = 'Could not connect: ' + reason;
                    result.classList.add('cs-actions__note--fail');
                }
            })
            .catch(function (error) {
                restore();
                if (result) {
                    result.textContent = 'Could not reach the server: ' + error.message;
                    result.classList.add('cs-actions__note--fail');
                }
            });
    });
}

/* ------------------------------------------------------------------
   Request console.
   ------------------------------------------------------------------ */

function initExplorer(root, explorerUrl) {
    const runButton = root.querySelector('[data-pd-run]');
    const methodEl = root.querySelector('#pd-ex-method');
    const pathEl = root.querySelector('#pd-ex-path');
    const bodyEl = root.querySelector('#pd-ex-body');
    const section = root.querySelector('[data-pd-result-section]');
    const pre = root.querySelector('[data-pd-result]');
    if (!runButton || !methodEl || !pathEl || !explorerUrl) return;

    // A suggestion fills the console. It does not send: putting a request on
    // the wire from a button labelled with a noun is how an operator sends
    // something they did not mean to.
    root.querySelectorAll('[data-pd-sample]').forEach(function (sample) {
        sample.addEventListener('click', function () {
            methodEl.value = sample.getAttribute('data-method') || 'GET';
            pathEl.value = sample.getAttribute('data-path') || '';
            if (bodyEl) bodyEl.value = '';
            pathEl.focus();
        });
    });

    runButton.addEventListener('click', function () {
        const restore = busy(runButton, 'Sending');
        if (section) section.hidden = false;
        if (pre) pre.textContent = 'Sending.';

        postJson(explorerUrl, {
            method: methodEl.value,
            path: pathEl.value,
            body: bodyEl ? bodyEl.value : '',
        })
            .then(function (data) {
                restore();
                if (pre) pre.textContent = JSON.stringify(data, null, 2);
            })
            .catch(function (error) {
                restore();
                if (pre) pre.textContent = 'Could not reach the server: ' + error.message;
            });
    });
}

/* ------------------------------------------------------------------
   Copy a cron command. Same contract as the one in ./store-settings.js
   (a button naming the id of the element holding the text), repeated
   here rather than shared because that module binds only on the
   multi-store pages and this one is single-store; making it shared
   would mean one of the two modules loading on a page it has nothing
   else to do on.
   ------------------------------------------------------------------ */

function initCopy(root) {
    root.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.getElementById(button.getAttribute('data-copy-target'));
            if (!target) return;

            const text = (target.textContent || '').trim();
            const done = function () {
                const original = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = original; }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    if (typeof window.showFlashError === 'function') {
                        window.showFlashError('Could not copy that. Select the command and copy it by hand.');
                    }
                });
                return;
            }

            if (typeof window.showFlashError === 'function') {
                window.showFlashError('This browser will not let the page copy for you. Select the command and copy it by hand.');
            }
        });
    });
}

/* ------------------------------------------------------------------
   Reference Data: filter the cached manufacturer list.

   Client side on purpose, and this is the one place in this conversion
   where that is the honest answer rather than a shortcut.
   PedallionCategoryController::index() loads every manufacturer with a
   plain ->get() and accepts no search parameter for them; its `q` is the
   CATEGORY search and is paginated. Adding a manufacturer search
   parameter would change the wire, which this task freezes, so the filter
   stays where it was: over rows already on the page.

   Rebuilt from the original in one respect. That one set row.style.display
   and matched against the whole row's textContent, so typing an id
   filtered by id as well by accident. This hides with a class the
   stylesheet owns and matches the name cell, which is what the box says it
   searches.
   ------------------------------------------------------------------ */

function initManufacturerFilter(root) {
    const input = root.querySelector('[data-pd-mfg-filter]');
    const table = root.querySelector('[data-pd-mfg-table]');
    const empty = root.querySelector('[data-pd-mfg-empty]');
    if (!input || !table) return;

    const rows = Array.from(table.querySelectorAll('tbody tr[data-pd-mfg-name]'));

    input.addEventListener('input', function () {
        const needle = input.value.trim().toLowerCase();
        let shown = 0;

        rows.forEach(function (row) {
            const name = (row.getAttribute('data-pd-mfg-name') || '').toLowerCase();
            const match = needle === '' || name.indexOf(needle) !== -1;
            row.hidden = !match;
            if (match) shown += 1;
        });

        if (empty) empty.hidden = shown !== 0;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const settings = document.querySelector('[data-pedallion-settings]');
    if (settings) {
        initTest(settings, settings.getAttribute('data-test-url') || '');
        initExplorer(settings, settings.getAttribute('data-explorer-url') || '');
        initCopy(settings);
    }

    const reference = document.querySelector('[data-pedallion-reference]');
    if (reference) {
        initManufacturerFilter(reference);
    }
});
