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

    setupTypeahead({
        inputId: 'manufacturer_search',
        hiddenId: 'manufacturer_id',
        listId: 'manufacturer_list',
        url: '/api/catalog/manufacturers?term=',
        onSelect: function (item) {
            const nameField = document.getElementById('product_name');
            if (nameField && !(nameField.value || '').trim()) nameField.focus();
        },
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

    setupTypeahead({
        inputId: 'category_search',
        listId: 'category_list',
        url: '/api/catalog/categories?term=',
        multi: true,
        tagContainerId: 'category_tags',
        hiddenName: 'category_ids[]',
    });

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
            outEl.value = cost.toFixed(4).replace(/\.?0+$/, '') || '0';

            const profit = p - cost;
            const negative = profit < 0;
            setFigure(profitEl, profit.toFixed(2), negative);
            setFigure(marginEl, (p > 0 ? (profit / p * 100).toFixed(2) : '0.00') + '%', negative);
            setFigure(markupEl, (cost > 0 ? (profit / cost * 100).toFixed(2) : '0.00') + '%', negative);
        };

        [priceEl, amtEl, pctEl, addEl].forEach(function (el) { el.addEventListener('input', calc); });
        calc();

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
