/*
 * Product create/edit form: manufacturer + category typeahead, the
 * manufacturer name prefix on the product name, and the "pick a real
 * manufacturer" submit guard.
 *
 * Why this file exists at all. All of this behaviour used to live in an
 * inline <script> at the bottom of resources/views/layouts/app.blade.php,
 * which is a protected file and, more to the point, is not the layout these
 * pages use any more. Moving catalog/products/create + edit onto
 * layouts.shell would have silently dropped the manufacturer lookup, the
 * category picker and the submit guard, so the behaviour is ported here as a
 * real module bundled through app.js (which both layouts load).
 *
 * It is scoped to [data-product-form], so it binds on the new pages only and
 * cannot double-bind against layouts.app's own inline copy on any legacy page
 * that still carries the same element ids.
 *
 * Three deliberate changes from the inline original, all noted in the task
 * report:
 *   1. Chips are built with createElement/textContent. The original did
 *      `chip.innerHTML = name + '<input ...>'` with a category name straight
 *      off the API, which is raw innerHTML with user-controlled data.
 *   2. The dropdown, its empty state and the chips carry `fm-` classes
 *      instead of the legacy `.typeahead-list` / `.tag-chip` ones, whose
 *      colours are raw blue hex with no dark-theme value.
 *   3. Endpoints come off data- attributes on the form rather than being
 *      Blade-interpolated, because this is a real JS file now.
 *
 * Everything else - the debounce, the id-normalising map over the API
 * response, the mousedown-not-click selection, the hidden-input contract
 * (manufacturer_id, category_ids[]) - is the original logic unchanged.
 */

function setupTypeahead(opts) {
    const input = document.getElementById(opts.inputId);
    const hidden = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
    const list = document.getElementById(opts.listId);
    const isMulti = !!opts.multi;
    const tagContainer = isMulti ? document.getElementById(opts.tagContainerId) : null;
    if (!input || !list) return;
    if (!isMulti && !hidden) return;

    let last = '';
    let timer = null;

    function clearList() { list.textContent = ''; list.style.display = 'none'; }
    function showList() { list.style.display = 'block'; }
    function hideList() { list.style.display = 'none'; }

    // Typing invalidates a previous pick: the hidden id goes back to 0 until
    // something is chosen from the list again. This is what makes the submit
    // guard below meaningful.
    if (!isMulti) {
        input.addEventListener('input', function () { hidden.value = '0'; });
    }

    function getSelectedIds() {
        if (!tagContainer) return [];
        return Array.from(tagContainer.querySelectorAll('.fm-tag')).map((c) => String(c.dataset.id));
    }

    function addTag(id, name) {
        if (!tagContainer) return;
        const chip = document.createElement('span');
        chip.className = 'fm-tag';
        chip.dataset.id = id;

        const label = document.createElement('span');
        label.textContent = name;
        chip.appendChild(label);

        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = opts.hiddenName;
        field.value = id;
        chip.appendChild(field);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'fm-tag__x';
        remove.setAttribute('aria-label', 'Remove ' + name);
        remove.textContent = '×';
        remove.addEventListener('click', function () { chip.remove(); });
        chip.appendChild(remove);

        tagContainer.appendChild(chip);
    }

    function render(items, query) {
        list.textContent = '';
        const selectedIds = isMulti ? getSelectedIds() : [];
        const filtered = isMulti
            ? items.filter((it) => selectedIds.indexOf(String(it.id)) === -1)
            : items;

        if (!filtered || !filtered.length) {
            if (query && query.length >= 1) {
                const empty = document.createElement('div');
                empty.className = 'fm-ta__empty';
                empty.textContent = isMulti && items.length > 0
                    ? 'Every match is already selected'
                    : 'No results found';
                list.appendChild(empty);

                if (typeof opts.onEmpty === 'function') {
                    const action = opts.onEmpty(query, {
                        select: function (id, name) {
                            input.value = name;
                            if (hidden) hidden.value = String(id);
                            clearList();
                            input.classList.remove('input-error');
                            if (opts.onSelect) opts.onSelect({ id: id, name: name });
                        },
                        close: clearList,
                    });
                    if (action && action.nodeType === 1) list.appendChild(action);
                }
                showList();
            } else {
                hideList();
            }
            return;
        }

        filtered.forEach(function (it) {
            const displayLabel = it.display_name || it.name;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = displayLabel;
            // mousedown, not click: the input's blur would tear the list down
            // before a click ever landed.
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (isMulti) {
                    addTag(it.id, displayLabel);
                    input.value = '';
                    clearList();
                } else {
                    input.value = displayLabel;
                    hidden.value = String(it.id);
                    clearList();
                    input.classList.remove('input-error');
                    if (opts.onSelect) opts.onSelect(it);
                }
            });
            list.appendChild(btn);
        });
        showList();
    }

    async function fetchItems(q) {
        try {
            const res = await fetch(opts.url + encodeURIComponent(q), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) return [];
            const data = await res.json();
            return (data || []).map(function (r) {
                const item = Object.assign({}, r);
                item.id = r.id ?? r.manufacturer_id ?? r.category_id ?? r.option_id ?? r.value_id ?? r.option_value_id;
                item.name = r.display_name ?? r.name ?? r.path;
                return item;
            }).filter((r) => r.id !== undefined && r.name);
        } catch (e) {
            return [];
        }
    }

    function doSearch() {
        const q = (input.value || '').trim();
        if (q.length < 1 && !opts.showAllOnFocus) { hideList(); return; }
        if (q === last) return;
        last = q;
        clearTimeout(timer);
        timer = setTimeout(async function () {
            const items = await fetchItems(q);
            render(items, q);
        }, 120);
    }

    input.addEventListener('focus', function () {
        const q = (input.value || '').trim();
        if (q.length >= 1 || opts.showAllOnFocus) {
            last = '';
            doSearch();
        }
    });
    input.addEventListener('input', doSearch);
    input.addEventListener('keyup', doSearch);

    document.addEventListener('click', function (e) {
        if (e.target !== input && !list.contains(e.target)) hideList();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-product-form]');
    if (!form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const manufacturerStoreUrl = form.dataset.manufacturerStoreUrl || '';

    // ---- Manufacturer name as a fixed prefix on the product name --------
    // The stored product name includes the manufacturer; the input holds the
    // remainder, and the prefix is prepended back on submit. Unchanged
    // behaviour, unchanged element ids.
    function updateMfgPrefix(mfgName) {
        const prefix = document.getElementById('name_mfg_prefix');
        const nameField = document.getElementById('product_name');
        if (!prefix || !nameField) return;
        const name = (mfgName || '').trim();
        if (name) {
            prefix.textContent = name;
            prefix.classList.remove('d-none');
            const current = (nameField.value || '').trim();
            if (current.toLowerCase().indexOf(name.toLowerCase()) === 0) {
                nameField.value = current.substring(name.length).replace(/^\s+/, '');
            }
        } else {
            prefix.classList.add('d-none');
            prefix.textContent = '';
        }
    }

    form.addEventListener('submit', function () {
        const prefix = document.getElementById('name_mfg_prefix');
        const nameField = document.getElementById('product_name');
        if (!prefix || !nameField) return;
        const mfg = (prefix.textContent || '').trim();
        const current = (nameField.value || '').trim();
        if (mfg && current && current.toLowerCase().indexOf(mfg.toLowerCase()) !== 0) {
            nameField.value = mfg + ' ' + current;
        } else if (mfg && !current) {
            nameField.value = mfg;
        }
    });

    setupTypeahead({
        inputId: 'manufacturer_search',
        hiddenId: 'manufacturer_id',
        listId: 'manufacturer_list',
        url: '/api/catalog/manufacturers?term=',
        onSelect: function (item) {
            updateMfgPrefix(item.name);
            const nameField = document.getElementById('product_name');
            if (nameField && !(nameField.value || '').trim()) nameField.focus();
        },
        // Nothing matched, so offer to create it inline rather than making
        // the operator leave a half-filled product form.
        onEmpty: function (query, api) {
            if (!manufacturerStoreUrl) return null;

            const row = document.createElement('div');
            row.className = 'fm-ta__new';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Add manufacturer "' + query + '"';
            btn.addEventListener('mousedown', async function (e) {
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = 'Creating...';
                try {
                    const res = await fetch(manufacturerStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name: query }),
                    });
                    const data = await res.json().catch(function () { return {}; });
                    if (!res.ok || !data.ok) {
                        btn.disabled = false;
                        btn.textContent = (data && data.message) ? data.message : 'Could not create it. Click to try again.';
                        return;
                    }
                    api.select(data.id, data.name);
                } catch (err) {
                    btn.disabled = false;
                    btn.textContent = 'Network error. Click to try again.';
                }
            });

            row.appendChild(btn);
            return row;
        },
    });

    const mfgInput = document.getElementById('manufacturer_search');
    if (mfgInput && (mfgInput.value || '').trim()) {
        updateMfgPrefix(mfgInput.value.trim());
    }

    setupTypeahead({
        inputId: 'category_search',
        listId: 'category_list',
        url: '/api/catalog/categories?term=',
        multi: true,
        tagContainerId: 'category_tags',
        hiddenName: 'category_ids[]',
    });

    // ---- A product must carry a real manufacturer -----------------------
    // Not a controller rule (manufacturer_id is nullable there), but the
    // image library is organised by manufacturer folder, so a product with
    // manufacturer 0 has nowhere for its pictures to live. Same guard the
    // pre-redesign form had, same message.
    const mfgHidden = document.getElementById('manufacturer_id');
    if (mfgHidden && mfgInput) {
        form.addEventListener('submit', function (e) {
            const val = parseInt(mfgHidden.value, 10);
            if (!val || val <= 0) {
                e.preventDefault();
                mfgInput.classList.add('input-error');
                mfgInput.focus();
                mfgInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const wrap = mfgInput.closest('.fm-field');
                if (wrap && !wrap.querySelector('.fm-error--js')) {
                    const err = document.createElement('div');
                    err.className = 'fm-error fm-error--js';
                    err.textContent = 'Choose a manufacturer from the list.';
                    wrap.appendChild(err);
                }
            }
        });

        mfgInput.addEventListener('input', function () {
            mfgInput.classList.remove('input-error');
            const wrap = mfgInput.closest('.fm-field');
            const err = wrap ? wrap.querySelector('.fm-error--js') : null;
            if (err) err.remove();
        });
    }

    // ---- Cost preview ---------------------------------------------------
    // cost = cost_amount + (cost_percentage / 100 * price) + cost_additional
    // is recomputed server-side on save (ProductController::store/update), so
    // this is a preview of what the server will store, not the value that
    // gets stored. Profit/margin/markup are read-only figures and submit
    // nothing.
    const priceEl = document.getElementById('product_price');
    const amtEl = document.getElementById('cost_amount');
    const pctEl = document.getElementById('cost_percentage');
    const addEl = document.getElementById('cost_additional');
    const outEl = document.getElementById('product_cost');

    if (priceEl && amtEl && pctEl && addEl && outEl) {
        const profitEl = document.getElementById('calc_profit');
        const marginEl = document.getElementById('calc_margin');
        const markupEl = document.getElementById('calc_markup');

        const setFigure = function (el, text, negative) {
            if (!el) return;
            el.textContent = text;
            el.classList.toggle('fm-neg', negative);
        };

        const calc = function () {
            const p = parseFloat(priceEl.value) || 0;
            const a = parseFloat(amtEl.value) || 0;
            const c = parseFloat(pctEl.value) || 0;
            const d = parseFloat(addEl.value) || 0;
            const cost = a + (c / 100 * p) + d;
            outEl.value = cost.toFixed(4);

            const profit = p - cost;
            const negative = profit < 0;
            setFigure(profitEl, profit.toFixed(2), negative);
            setFigure(marginEl, (p > 0 ? (profit / p * 100).toFixed(2) : '0.00') + '%', negative);
            setFigure(markupEl, (cost > 0 ? (profit / cost * 100).toFixed(2) : '0.00') + '%', negative);
        };

        [priceEl, amtEl, pctEl, addEl].forEach(function (el) { el.addEventListener('input', calc); });
        calc();

        // ---- Cost override ------------------------------------------------
        // Cost normally arrives from a received purchase order. Editing it by
        // hand is allowed but has to be asked for, so the three inputs start
        // readonly and this unlocks them behind the shared confirm dialog.
        const overrideBtn = document.getElementById('cost-override-btn');
        const lockNote = document.getElementById('cost-lock-note');
        const costFields = [amtEl, pctEl, addEl];
        let unlocked = false;

        if (overrideBtn) {
            overrideBtn.addEventListener('click', function () {
                if (!unlocked) {
                    window.confirmModal('Cost is normally set when a purchase order is received. Edit it by hand anyway?').then(function (ok) {
                        if (!ok) return;
                        costFields.forEach(function (el) {
                            el.removeAttribute('readonly');
                            el.removeAttribute('tabindex');
                        });
                        if (lockNote) lockNote.textContent = 'Editing by hand. Saving will overwrite the purchase-order figure.';
                        overrideBtn.textContent = 'Lock';
                        unlocked = true;
                    });
                } else {
                    costFields.forEach(function (el) {
                        el.setAttribute('readonly', 'readonly');
                        el.setAttribute('tabindex', '-1');
                    });
                    if (lockNote) lockNote.textContent = 'Set when a purchase order is received.';
                    overrideBtn.textContent = 'Edit by hand';
                    unlocked = false;
                }
            });
        }
    }
});
