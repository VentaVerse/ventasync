function makeProgress(overlaySelector) {
    return function showProgress(title) {
        const overlay = document.querySelector(overlaySelector);
        if (!overlay) return;
        const titleEl = overlay.querySelector('[data-progress-title]');
        if (titleEl && title) titleEl.textContent = title;
        overlay.classList.add('active');
    };
}

function tickedIds(attr) {
    return Array.from(document.querySelectorAll('[data-row-check]'))
        .filter((box) => box.checked)
        .map((box) => (attr ? (box.getAttribute(attr) || '') : box.value))
        .filter((id) => String(id) !== '');
}

function tickedNames() {
    return Array.from(document.querySelectorAll('[data-row-check]'))
        .filter((box) => box.checked)
        .map((box) => {
            const row = box.closest('tr');
            const name = row && row.querySelector('.co-item__name, .x-row-link');
            return name ? name.textContent.trim() : '';
        })
        .filter((name) => name !== '');
}

const CONFIRM_NAME_LIMIT = 6;

function buildBulkConfirm(message, ids) {
    const names = tickedNames();
    const noun = ids.length === 1 ? 'product' : 'products';
    let out = message + '\n\n' + ids.length + ' ' + noun;

    if (names.length === ids.length) {
        out += ':\n' + names.slice(0, CONFIRM_NAME_LIMIT).join('\n');
        if (names.length > CONFIRM_NAME_LIMIT) {
            out += '\nand ' + (names.length - CONFIRM_NAME_LIMIT) + ' more';
        }
    }

    return out;
}

function injectIds(form, ids, fieldName) {
    form.querySelectorAll('input[name="' + fieldName + '"]').forEach((n) => n.remove());
    ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = fieldName;
        input.value = id;
        form.appendChild(input);
    });
}

function initBulkForms(config, showProgress) {
    document.querySelectorAll('form[data-bulk-form]').forEach((form) => {
        form.addEventListener('submit', function (e) {
            if (form.dataset.bulkSubmitting === '1') {
                form.dataset.bulkSubmitting = '';
                return;
            }

            e.preventDefault();

            const field = form.getAttribute('data-bulk-field') || config.bulkField;
            const attr = form.getAttribute('data-bulk-attr') || '';

            let ids = tickedIds(attr);
            const verbCodes = form.getAttribute('data-verb-codes');
            if (verbCodes) {
                const codes = verbCodes.split(',');
                const allowed = Array.from(document.querySelectorAll('[data-row-check]'))
                    .filter((box) => box.checked && codes.includes(box.getAttribute('data-live') || ''))
                    .map((box) => (attr ? (box.getAttribute(attr) || '') : box.value));
                ids = ids.filter((id) => allowed.includes(id));
            }
            if (!ids.length) {
                const message = form.getAttribute('data-bulk-empty') || config.emptyMessage;
                if (typeof window.showFlashError === 'function') {
                    window.showFlashError(message);
                }
                return;
            }

            const submit = function () {
                injectIds(form, ids, field);
                showProgress(config.bulkTitle(ids.length));
                const button = form.querySelector('button[type="submit"]');
                if (button) button.disabled = true;
                // Native submit(), not requestSubmit(): a re-entrant requestSubmit during a submit is silently dropped.
                form.submit();
            };

            const message = form.getAttribute('data-bulk-confirm');
            if (message && typeof window.confirmModal === 'function') {
                window.confirmModal(buildBulkConfirm(message, ids), document.activeElement).then(function (ok) {
                    if (ok) submit();
                });
                return;
            }

            submit();
        });
    });
}

function initSlowActions(config, showProgress) {
    document.querySelectorAll('form[data-slow-action]').forEach((form) => {
        const only = form.getAttribute('data-slow-action');
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            if (only && e.submitter && e.submitter.name !== only) return;
            showProgress(config.slowTitle);
        });
    });
}

function buildResultButton(item, onPick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('role', 'option');

    const row = document.createElement('span');
    row.className = 'cc-ta__row';

    if (item.image) {
        const thumb = document.createElement('span');
        thumb.className = 'cc-thumb cc-thumb--sm';
        const img = document.createElement('img');
        img.src = item.image;
        img.alt = '';
        img.loading = 'lazy';
        thumb.appendChild(img);
        row.appendChild(thumb);
    }

    const body = document.createElement('span');
    body.className = 'cc-ta__body';

    const name = document.createElement('span');
    name.className = 'cc-ta__name';
    name.textContent = item.name || 'Unnamed product';
    body.appendChild(name);

    const meta = document.createElement('span');
    meta.className = 'cc-ta__meta';
    const identifiers = [];
    if (item.model) identifiers.push(String(item.model));
    if (item.sku && item.sku !== item.model) identifiers.push(String(item.sku));
    identifiers.push('ID ' + item.product_id);
    meta.textContent = identifiers.join(' / ');
    body.appendChild(meta);

    if (item.linked) {
        const held = document.createElement('span');
        held.className = 'cc-ta__held';
        held.textContent = 'Already linked to ' + item.linked;
        body.appendChild(held);
        button.disabled = true;
        button.classList.add('is-held');
    }

    if (item.options && item.options.length) {
        item.options.forEach((option) => {
            const line = document.createElement('span');
            line.className = 'cc-ta__var';
            const label = option.name ? option.name + ' / ' : '';
            line.textContent = label + option.sku + ' / ' + option.qty + ' in stock';
            body.appendChild(line);
        });
    }

    row.appendChild(body);
    button.appendChild(row);

    button.addEventListener('click', function () {
        if (item.linked) return;
        onPick(item);
    });

    return button;
}

function looksAlike(itemName, itemSkus, picked) {
    const norm = (v) => String(v || '').trim().toLowerCase();
    const mine = new Set((picked.skus || [picked.sku, picked.model]).map(norm).filter(Boolean));
    if (itemSkus.map(norm).some((sku) => sku && mine.has(sku))) return true;
    const words = (name) => new Set(norm(name).replace(/[^a-z0-9]+/g, ' ').split(' ').filter((w) => w.length >= 4));
    const theirs = words(itemName);
    for (const w of words(picked.name)) {
        if (theirs.has(w)) return true;
    }
    return false;
}

export function initCatalogPicker(form) {
    if (form.dataset.pickerBound) return;
    form.dataset.pickerBound = '1';

    const searchUrl = form.getAttribute('data-search-url');
    const input = form.querySelector('[data-unmatched-search]');
    const list = form.querySelector('[data-unmatched-results]');
    const hidden = form.querySelector('[data-unmatched-id]');
    const submit = form.querySelector('[data-unmatched-submit]');
    if (!searchUrl || !input || !list || !hidden) return;

    let timer = null;

    function hide() {
        list.style.display = 'none';
    }

    function clear() {
        list.textContent = '';
        hide();
    }

    function show() {
        list.style.display = 'block';
    }

    function message(text) {
        list.textContent = '';
        const empty = document.createElement('div');
        empty.className = 'fm-ta__empty';
        empty.textContent = text;
        list.appendChild(empty);
        show();
    }

    function pick(item) {
        hidden.value = String(item.product_id);
        input.value = (item.name || 'Unnamed product') + ' (ID ' + item.product_id + ')';
        clear();
        if (submit) submit.disabled = false;
        if (submit && form.dataset.itemName !== undefined) {
            const itemSkus = (form.dataset.itemSkus || '').split('|').filter(Boolean);
            if (!looksAlike(form.dataset.itemName, itemSkus, item)) {
                submit.setAttribute('data-confirm', 'These do not look like the same product: \u201c' + form.dataset.itemName
                    + '\u201d on the store and \u201c' + (item.name || 'Unnamed product') + '\u201d in your catalog. Link them anyway?');
                submit.setAttribute('data-confirm-tone', 'primary');
                submit.setAttribute('data-confirm-verb', 'Link anyway');
            } else {
                submit.removeAttribute('data-confirm');
                submit.removeAttribute('data-confirm-tone');
                submit.removeAttribute('data-confirm-verb');
            }
        }
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    input.addEventListener('input', function () {
        hidden.value = '';
        if (submit) submit.disabled = true;
        hidden.dispatchEvent(new Event('change', { bubbles: true }));

        const query = input.value.trim();
        if (query.length < 2) {
            clear();
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            fetch(searchUrl + '?q=' + encodeURIComponent(query), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.json())
                .then((items) => {
                    if (!Array.isArray(items) || !items.length) {
                        message('No catalog product matches that.');
                        return;
                    }
                    list.textContent = '';
                    items.forEach((item) => list.appendChild(buildResultButton(item, pick)));
                    show();
                })
                .catch(() => {
                    message('Could not reach the catalog. Try again.');
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!form.contains(e.target)) hide();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') clear();
    });
}

function updateBulkVerbs() {
    const forms = Array.from(document.querySelectorAll('form[data-bulk-verb]'));
    if (!forms.length) return;
    const picked = Array.from(document.querySelectorAll('[data-row-check]')).filter((b) => b.checked);
    let covered = 0;
    forms.forEach((form) => {
        const codes = (form.getAttribute('data-verb-codes') || '').split(',');
        const n = picked.filter((b) => codes.includes(b.getAttribute('data-live') || '')).length;
        covered += n;
        const button = form.querySelector('button[type="submit"]');
        if (button) button.textContent = (form.getAttribute('data-verb-label') || ':n').replace(':n', n);
        form.hidden = n === 0;
    });
    const note = document.querySelector('[data-bulk-skipped]');
    if (note) {
        const skipped = picked.length - covered;
        note.textContent = picked.length > 0 && skipped > 0 ? skipped + ' cannot be changed here' : '';
    }
}

function initBulkVerbs() {
    if (!document.querySelector('form[data-bulk-verb]')) return;
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[type="checkbox"]')) {
            setTimeout(updateBulkVerbs, 0);
        }
    });
    updateBulkVerbs();
}

export function initChannelListings(config) {
    if (!document.querySelector(config.overlaySelector)) return;

    const showProgress = makeProgress(config.overlaySelector);

    document.addEventListener('busy:show', function (e) {
        showProgress((e.detail && e.detail.title) || '');
    });

    initBulkForms(config, showProgress);
    initSlowActions(config, showProgress);
    document.querySelectorAll('form[data-unmatched-link]').forEach(initCatalogPicker);
    initBulkVerbs();
}
