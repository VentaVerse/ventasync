// Rows are built with textContent: remote store status names are untrusted and must never run as markup.

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
            return { error: 'The server did not answer with a result. Try again, and sign in again if this keeps happening.' };
        });
    });
}

function flashOk(message) {
    if (typeof window.showFlashSuccess === 'function') window.showFlashSuccess(message);
}

function flashFail(message) {
    if (typeof window.showFlashError === 'function') window.showFlashError(message);
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

function readJsonIsland(root, selector) {
    const el = root.querySelector(selector);
    if (!el) return [];
    try {
        const parsed = JSON.parse(el.textContent || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function initColour(root) {
    root.querySelectorAll('[data-colour-pair]').forEach(function (pair) {
        const swatch = pair.querySelector('[data-colour-input]');
        const hex = pair.querySelector('[data-colour-hex]');
        if (!swatch || !hex) return;

        swatch.addEventListener('input', function () { hex.value = swatch.value; });
        hex.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) swatch.value = hex.value;
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
                    flashFail('Could not copy that. Select the command and copy it by hand.');
                });
                return;
            }
            flashFail('This browser will not let the page copy for you. Select the command and copy it by hand.');
        });
    });
}

function initTest(root, config) {
    const button = root.querySelector('[data-test-connection]');
    const result = root.querySelector('[data-test-result]');
    if (!button || !config.testUrl) return;

    button.addEventListener('click', function () {
        const restore = busy(button, 'Testing');
        if (result) {
            result.textContent = 'Talking to ' + config.label + '.';
            result.classList.remove('cs-actions__note--ok', 'cs-actions__note--fail');
        }

        const payload = { store_id: config.storeId };

        config.testInclude.forEach(function (field) {
            const input = root.querySelector('[name="' + field + '"]');
            if (input && String(input.value || '').trim() !== '') {
                payload[field] = input.value;
            }
        });

        postJson(config.testUrl, payload)
            .then(function (data) {
                restore();
                if (!result) return;

                const ok = !!data.ok && (!data.body || data.body.success !== false);
                if (ok) {
                    const info = (data.body && data.body.data) || {};
                    const shop = data.shop || {};
                    const parts = [];
                    if (info.store) parts.push(String(info.store));
                    if (info.version) parts.push('version ' + info.version);
                    if (shop.name) parts.push(String(shop.name));
                    if (shop.plan_name) parts.push(String(shop.plan_name));
                    if (shop.currency) parts.push(String(shop.currency));
                    result.textContent = data.message
                        ? String(data.message)
                        : (parts.length ? 'Connected to ' + parts.join(', ') + '.' : 'Connected.');
                    result.classList.add('cs-actions__note--ok');

                    const step = root.querySelector('[data-flow-step="connected"]');
                    const state = root.querySelector('[data-flow-state="connected"]');
                    if (state) state.textContent = 'Reached just now';
                    if (step) step.setAttribute('data-state', 'done');
                } else {
                    const reason = data.message || (data.body && data.body.error) || data.error || 'no reason given';
                    result.textContent = data.message ? String(data.message) : 'Could not connect: ' + reason;
                    result.classList.add('cs-actions__note--fail');

                    const failedStep = root.querySelector('[data-flow-step="connected"]');
                    const failedState = root.querySelector('[data-flow-state="connected"]');
                    if (failedState) failedState.textContent = 'Refused just now';
                    if (failedStep) failedStep.setAttribute('data-state', 'failed');
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

function buildMapSelect(row, config, erpStatuses) {
    const wrap = document.createElement('div');
    wrap.className = 'x-select-wrap cs-map-select';

    const select = document.createElement('select');
    select.className = 'x-input';
    select.setAttribute('data-map-select', '');
    select.setAttribute('data-map-id', String(row.id));
    select.setAttribute('data-map-name', row.name);
    select.setAttribute('aria-label', 'Sales order status for ' + row.name);

    const none = document.createElement('option');
    none.value = '0';
    none.textContent = config.unmappedLabel;
    select.appendChild(none);

    let chosen = row.mapped > 0 ? String(row.mapped) : '0';
    if (chosen === '0' && config.nameMatch) {
        const needle = row.name.toLowerCase().trim();
        for (let i = 0; i < erpStatuses.length; i += 1) {
            if (String(erpStatuses[i].name).toLowerCase().trim() === needle) {
                chosen = String(erpStatuses[i].id);
                break;
            }
        }
    }

    erpStatuses.forEach(function (erp) {
        const option = document.createElement('option');
        option.value = String(erp.id);
        option.textContent = erp.name;
        if (String(erp.id) === chosen) option.selected = true;
        select.appendChild(option);
    });

    wrap.appendChild(select);
    return wrap;
}

function buildMapTable(rows, config, erpStatuses) {
    const wrap = document.createElement('div');
    wrap.className = 'x-table-wrap';

    const table = document.createElement('table');
    table.className = 'x-table';

    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    [config.sourceHeading, 'Sales order status'].forEach(function (text, index) {
        const th = document.createElement('th');
        if (index === 0) th.className = 'cs-col-src';
        th.textContent = text;
        headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    rows.forEach(function (row) {
        const tr = document.createElement('tr');

        const source = document.createElement('td');
        source.className = 'cs-col-src';
        source.setAttribute('data-label', config.sourceHeading);
        const code = document.createElement('span');
        code.className = 'cs-code';
        code.textContent = String(row.id);
        source.appendChild(code);
        const label = document.createElement('span');
        label.className = 'cs-map-label';
        label.textContent = row.name;
        source.appendChild(label);
        tr.appendChild(source);

        const target = document.createElement('td');
        target.setAttribute('data-label', 'Sales order status');
        target.appendChild(buildMapSelect(row, config, erpStatuses));
        tr.appendChild(target);

        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    return wrap;
}

function initStatusMap(root, config) {
    const container = root.querySelector('[data-status-map]');
    if (!container) return;

    const erpStatuses = readJsonIsland(root, '[data-erp-statuses]');
    const saveBar = root.querySelector('[data-save-map-bar]');
    const fetchButton = root.querySelector('[data-fetch-statuses]');
    const saveButton = root.querySelector('[data-save-map]');

    if (fetchButton && config.fetchStatusesUrl) {
        fetchButton.addEventListener('click', function () {
            const restore = busy(fetchButton, 'Fetching');

            postJson(config.fetchStatusesUrl, { store_id: config.storeId })
                .then(function (data) {
                    restore();

                    if (data.error) {
                        flashFail(data.error);
                        return;
                    }

                    const raw = Array.isArray(data.statuses) ? data.statuses : [];
                    if (!raw.length) {
                        flashFail('The store did not return any order statuses.');
                        return;
                    }

                    const rows = raw
                        .map(function (item) {
                            return {
                                id: parseInt(item[config.serverIdKey] || item.id || 0, 10),
                                name: String(item.name || item.label || ''),
                                mapped: parseInt(item[config.serverMappedKey] || 0, 10),
                            };
                        })
                        .filter(function (row) { return row.id > 0; });

                    if (!rows.length) {
                        flashFail('The store returned statuses this page could not read.');
                        return;
                    }

                    container.textContent = '';
                    container.appendChild(buildMapTable(rows, config, erpStatuses));
                    if (saveBar) saveBar.hidden = false;

                    flashOk('Fetched ' + rows.length + (rows.length === 1 ? ' status' : ' statuses') + '. Choose what each one becomes, then save.');
                })
                .catch(function (error) {
                    restore();
                    flashFail('Could not fetch the statuses: ' + error.message);
                });
        });
    }

    if (saveButton && config.saveMapUrl) {
        saveButton.addEventListener('click', function () {
            const selects = Array.from(container.querySelectorAll('[data-map-select]'));
            if (!selects.length) {
                flashFail('Nothing to save. Fetch the statuses from the store first.');
                return;
            }

            const mappings = selects.map(function (select) {
                const row = {};
                row[config.mapIdField] = parseInt(select.getAttribute('data-map-id'), 10);
                row[config.mapNameField] = select.getAttribute('data-map-name') || '';
                row.order_status_id = parseInt(select.value, 10) || 0;
                return row;
            });

            const restore = busy(saveButton, 'Saving');

            postJson(config.saveMapUrl, { store_id: config.storeId, mappings: mappings })
                .then(function (data) {
                    restore();
                    if (data.error) {
                        flashFail(data.error);
                        return;
                    }
                    flashOk(data.status || 'Mapping saved.');
                    root.dispatchEvent(new CustomEvent('store-settings:mapping-saved', { bubbles: true }));
                })
                .catch(function (error) {
                    restore();
                    flashFail('Could not save the mapping: ' + error.message);
                });
        });
    }
}

function reportRun(data, fallback) {
    if (data.error) {
        flashFail(data.error);
        return false;
    }
    flashOk(data.status || fallback);
    return true;
}

function initRuns(root, config) {
    const from = root.querySelector('[data-pull-from]');
    const to = root.querySelector('[data-pull-to]');

    root.querySelectorAll('[data-sync-run]').forEach(function (button) {
        if (!config.syncUrl) return;

        button.addEventListener('click', function () {
            const entity = button.getAttribute('data-entity') || 'orders';
            const full = button.getAttribute('data-full') === '1';
            const restore = busy(button, full ? 'Importing' : 'Running');

            const body = {
                store_id: config.storeId,
                entity: entity,
                full: full ? 1 : 0,
                no_stock: (full && entity === 'orders') ? 1 : 0,
                date_from: (entity === 'orders' && !full && from) ? (from.value || '') : '',
                date_to: (entity === 'orders' && !full && to) ? (to.value || '') : '',
            };

            postJson(config.syncUrl, body)
                .then(function (data) {
                    restore();
                    const ok = reportRun(data, 'Finished.');
                    if (ok && full) {
                        root.dispatchEvent(new CustomEvent('store-settings:step-done', {
                            bubbles: true,
                            detail: { entity: entity },
                        }));
                    }
                })
                .catch(function (error) {
                    restore();
                    flashFail('That did not finish: ' + error.message);
                });
        });
    });

    const pushQty = root.querySelector('[data-push-qty]');
    if (pushQty && config.pushQtyUrl) {
        pushQty.addEventListener('click', function () {
            const restore = busy(pushQty, 'Pushing');
            postJson(config.pushQtyUrl, { store_id: config.storeId })
                .then(function (data) { restore(); reportRun(data, 'Stock pushed.'); })
                .catch(function (error) { restore(); flashFail('That did not finish: ' + error.message); });
        });
    }

    const pushAll = root.querySelector('[data-push-all]');
    if (pushAll && config.pushUrl) {
        pushAll.addEventListener('click', function () {
            const message = pushAll.getAttribute('data-confirm-message');

            const run = function () {
                const restore = busy(pushAll, 'Pushing');
                postJson(config.pushUrl, { store_id: config.storeId })
                    .then(function (data) { restore(); reportRun(data, 'Everything pushed.'); })
                    .catch(function (error) { restore(); flashFail('That did not finish: ' + error.message); });
            };

            if (message && typeof window.confirmModal === 'function') {
                window.confirmModal(message, pushAll).then(function (ok) { if (ok) run(); });
                return;
            }
            run();
        });
    }
}

const STEP_DEPENDENCIES = {
    options: ['categories', 'manufacturers'],
    products: ['options'],
    status_map: ['products'],
    orders: ['status_map'],
};

function initImport(root, config) {
    const lock = root.querySelector('[data-import-lock]');
    const steps = root.querySelector('[data-import-steps]');
    if (!lock || !steps) return;

    const password = lock.querySelector('[data-import-password]');
    const unlock = lock.querySelector('[data-import-unlock]');
    const error = lock.querySelector('[data-import-error]');
    const actions = root.querySelector('[data-import-actions]');
    const pushSection = root.querySelector('[data-import-push]');

    const done = {};

    function stepEl(entity) {
        return steps.querySelector('[data-import-step="' + entity + '"]');
    }

    function refresh() {
        steps.querySelectorAll('[data-import-step]').forEach(function (step) {
            const entity = step.getAttribute('data-import-step');
            const deps = STEP_DEPENDENCIES[entity];
            const button = step.querySelector('.x-btn');
            const state = step.querySelector('[data-step-state]');

            const ready = !deps || deps.every(function (d) { return done[d]; });

            if (done[entity]) {
                step.removeAttribute('data-step-locked');
                if (button) button.disabled = true;
                if (state) state.textContent = 'Done';
                return;
            }

            if (ready) {
                step.removeAttribute('data-step-locked');
                if (button) button.disabled = false;
                if (state) state.textContent = '';
            } else {
                step.setAttribute('data-step-locked', '');
                if (button) button.disabled = true;
                if (state) state.textContent = 'Waiting on the step above';
            }
        });
    }

    function markDone(entity) {
        if (!stepEl(entity)) return;
        done[entity] = true;
        refresh();
    }

    if (unlock && config.verifyUrl) {
        const attempt = function () {
            const value = password ? password.value : '';
            if (!value) {
                if (error) { error.textContent = 'Type your password first.'; error.hidden = false; }
                return;
            }

            const restore = busy(unlock, 'Checking');
            if (error) error.hidden = true;

            postJson(config.verifyUrl, { password: value })
                .then(function (data) {
                    restore();
                    if (!data.ok) {
                        if (error) {
                            error.textContent = data.error || 'That password did not match.';
                            error.hidden = false;
                        }
                        if (password) { password.value = ''; password.focus(); }
                        return;
                    }
                    lock.hidden = true;
                    steps.hidden = false;
                    if (actions) actions.hidden = false;
                    if (pushSection) pushSection.hidden = false;
                    refresh();
                })
                .catch(function (err) {
                    restore();
                    if (error) { error.textContent = 'Could not check that: ' + err.message; error.hidden = false; }
                });
        };

        unlock.addEventListener('click', attempt);
        if (password) {
            password.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); attempt(); }
            });
        }
    }

    root.addEventListener('store-settings:step-done', function (e) {
        markDone(e.detail && e.detail.entity);
    });
    root.addEventListener('store-settings:mapping-saved', function () {
        markDone('status_map');
    });

    steps.querySelectorAll('[data-goto-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = button.getAttribute('data-goto-tab');
            const tab = root.querySelector('[data-cs-tab="' + id + '"]');
            if (tab) { tab.click(); return; }
            const prefix = root.getAttribute('data-tab-prefix') || '';
            const url = new URL(window.location.href);
            url.searchParams.set('tab', prefix && id.indexOf(prefix) === 0 ? id.slice(prefix.length) : id);
            window.location.assign(url);
        });
    });

    const reset = root.querySelector('[data-import-reset]');
    if (reset) {
        reset.addEventListener('click', function () {
            Object.keys(done).forEach(function (key) { delete done[key]; });
            refresh();
        });
    }

    refresh();
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-store-settings]');
    if (!root) return;

    const config = {
        storeId: root.getAttribute('data-store-id'),
        label: root.getAttribute('data-channel-label') || 'the store',
        testUrl: root.getAttribute('data-test-url') || '',
        testInclude: (root.getAttribute('data-test-include') || '')
            .split(',')
            .map(function (name) { return name.trim(); })
            .filter(Boolean),
        fetchStatusesUrl: root.getAttribute('data-fetch-statuses-url') || '',
        saveMapUrl: root.getAttribute('data-save-map-url') || '',
        mapIdField: root.getAttribute('data-map-id-field') || 'status_id',
        mapNameField: root.getAttribute('data-map-name-field') || 'status_name',
        serverIdKey: root.getAttribute('data-map-server-id') || 'id',
        serverMappedKey: root.getAttribute('data-map-server-key') || 'order_status_id',
        nameMatch: root.hasAttribute('data-map-name-match'),
        sourceHeading: (root.getAttribute('data-channel-label') || 'Channel') + ' status',
        unmappedLabel: 'Leave the raw ' + (root.getAttribute('data-channel-label') || 'channel') + ' status',
        syncUrl: root.getAttribute('data-sync-url') || '',
        pushUrl: root.getAttribute('data-push-url') || '',
        pushQtyUrl: root.getAttribute('data-push-qty-url') || '',
        verifyUrl: root.getAttribute('data-verify-url') || '',
    };

    initColour(root);
    initCopy(root);
    initTest(root, config);
    initStatusMap(root, config);
    initRuns(root, config);
    initImport(root, config);
});
