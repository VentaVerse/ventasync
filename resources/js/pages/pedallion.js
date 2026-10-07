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
            return { ok: false, error: 'The server did not answer with a result. Try again, and sign in again if this keeps happening.' };
        });
    });
}

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

function initExplorer(root, explorerUrl) {
    const runButton = root.querySelector('[data-pd-run]');
    const methodEl = root.querySelector('#pd-ex-method');
    const pathEl = root.querySelector('#pd-ex-path');
    const bodyEl = root.querySelector('#pd-ex-body');
    const section = root.querySelector('[data-pd-result-section]');
    const pre = root.querySelector('[data-pd-result]');
    if (!runButton || !methodEl || !pathEl || !explorerUrl) return;

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
