function setFlash(region, message) {
    if (!region) return;
    region.textContent = '';
    if (!message) return;

    const note = document.createElement('div');
    note.className = 'fm-note fm-note--fail';
    const body = document.createElement('div');
    body.className = 'fm-note__body';
    body.textContent = message;
    note.appendChild(body);
    region.appendChild(note);
    region.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function productSearch(opts) {
    const { input, list, url, warehouseSelect, onPick, groupAdd, haveLabel } = opts;
    if (!input || !list || !url) return;

    let timer = null;

    function close() {
        list.classList.remove('is-open');
        list.textContent = '';
    }

    function row(item, extraClass) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wh-ac__item' + (extraClass ? ' ' + extraClass : '');

        const name = document.createElement('span');
        name.className = 'wh-ac__name';
        name.textContent = item.name || '';
        btn.appendChild(name);

        if (item.sku) {
            const sku = document.createElement('span');
            sku.className = 'wh-ac__sku';
            sku.textContent = item.sku;
            btn.appendChild(sku);
        }

        if (item.available_qty !== null && item.available_qty !== undefined) {
            const have = document.createElement('span');
            have.className = 'wh-ac__have';
            have.textContent = haveLabel + ' ' + item.available_qty;
            btn.appendChild(have);
        }

        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            onPick(item);
            close();
            input.value = '';
        });

        return btn;
    }

    function render(items) {
        list.textContent = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'wh-ac__empty';
            empty.textContent = 'No products found';
            list.appendChild(empty);
            list.classList.add('is-open');
            return;
        }

        const seenGroups = {};

        items.forEach(function (item) {
            if (groupAdd && item.has_options && item.option_group && !seenGroups[item.option_group]) {
                const group = items.filter(function (v) { return v.option_group === item.option_group; });
                seenGroups[item.option_group] = group;

                const baseName = String(item.name || '').split(' · ')[0];
                const all = document.createElement('button');
                all.type = 'button';
                all.className = 'wh-ac__item wh-ac__item--all';
                const label = document.createElement('span');
                label.className = 'wh-ac__name';
                label.textContent = 'Add all ' + item.option_count + ' variations of ' + baseName;
                all.appendChild(label);
                all.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    group.forEach(onPick);
                    close();
                    input.value = '';
                });
                list.appendChild(all);
            }

            list.appendChild(row(item, item.has_options ? 'wh-ac__item--child' : null));
        });

        list.classList.add('is-open');
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const term = input.value.trim();

        if (term.length < 2) {
            close();
            return;
        }

        timer = setTimeout(function () {
            const whId = (warehouseSelect && warehouseSelect.value) || 0;
            const query = url + '?term=' + encodeURIComponent(term) + '&warehouse_id=' + encodeURIComponent(whId);

            fetch(query, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(render)
                .catch(function () { close(); });
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!list.contains(e.target) && e.target !== input) close();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });

    return { close: close };
}

function lineList(opts) {
    const { host, empty, build, onChange } = opts;
    let nextIdx = 0;

    function lines() {
        return Array.from(host.querySelectorAll('[data-wh-line]'));
    }

    function syncEmpty() {
        if (empty) empty.hidden = lines().length > 0;
        if (onChange) onChange(lines());
    }

    function renumber() {
        lines().forEach(function (line, i) {
            line.querySelectorAll('input, select').forEach(function (el) {
                if (el.name) el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']');
            });
        });
        nextIdx = lines().length;
    }

    function find(productId, povId) {
        return lines().find(function (line) {
            return Number(line.dataset.productId) === Number(productId)
                && Number(line.dataset.povId) === Number(povId);
        });
    }

    function add(item) {
        const productId = Number(item.product_id);
        const povId = Number(item.product_option_value_id || 0);

        const existing = find(productId, povId);
        if (existing) {
            const qty = existing.querySelector('[data-wh-qty]');
            if (qty) { qty.focus(); qty.select(); }
            return;
        }

        const i = nextIdx++;
        const line = document.createElement('div');
        line.className = 'wh-line';
        line.setAttribute('data-wh-line', '');
        line.dataset.productId = String(productId);
        line.dataset.povId = String(povId);

        build(line, i, item);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'wh-line__x';
        remove.setAttribute('data-wh-remove', '');
        remove.setAttribute('aria-label', 'Remove this line');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '13');
        svg.setAttribute('height', '13');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M18 6 6 18M6 6l12 12');
        svg.appendChild(path);
        remove.appendChild(svg);
        line.appendChild(remove);

        host.appendChild(line);
        syncEmpty();

        const qty = line.querySelector('[data-wh-qty]');
        if (qty) { qty.focus(); qty.select(); }
    }

    host.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-wh-remove]');
        if (!btn) return;
        const line = btn.closest('[data-wh-line]');
        if (line) line.remove();
        renumber();
        syncEmpty();
    });

    renumber();
    syncEmpty();

    return { add: add, lines: lines, syncEmpty: syncEmpty };
}

function cell(line, className, label) {
    const el = document.createElement('span');
    if (className) el.className = className;
    if (label) el.setAttribute('data-label', label);
    line.appendChild(el);
    return el;
}

function hidden(parent, name, value) {
    const el = document.createElement('input');
    el.type = 'hidden';
    el.name = name;
    el.value = value;
    parent.appendChild(el);
    return el;
}

function initTransferForm() {
    const form = document.querySelector('[data-wh-transfer-form]');
    if (!form) return;

    const host = form.querySelector('[data-wh-lines]');
    const empty = form.querySelector('[data-wh-empty]');
    const fromSelect = form.querySelector('[data-wh-from]');
    const toSelect = form.querySelector('[data-wh-to]');
    const searchInput = form.querySelector('[data-wh-search]');
    const searchList = form.querySelector('[data-wh-results]');
    const totalRow = form.querySelector('[data-wh-total-row]');
    const totalValue = form.querySelector('[data-wh-total]');
    const url = form.dataset.productSearchUrl;

    if (!host) return;

    function checkQty(input) {
        const available = parseInt(input.dataset.available, 10);
        const qty = parseInt(input.value, 10) || 0;
        const over = Number.isFinite(available) && qty > available;
        input.classList.toggle('is-invalid', over);
        return !over;
    }

    function retotal(lines) {
        if (!totalValue) return;
        let sum = 0;
        lines.forEach(function (line) {
            const qty = line.querySelector('[data-wh-qty]');
            sum += parseInt(qty && qty.value, 10) || 0;
        });
        totalValue.textContent = String(sum);
        if (totalRow) totalRow.hidden = lines.length === 0;
    }

    const list = lineList({
        host: host,
        empty: empty,
        onChange: retotal,
        build: function (line, i, item) {
            const name = cell(line, 'wh-line__name');
            hidden(name, 'items[' + i + '][product_id]', item.product_id);
            hidden(name, 'items[' + i + '][product_option_value_id]', item.product_option_value_id || 0);
            const b = document.createElement('b');
            b.textContent = item.name || '';
            name.appendChild(b);

            const sku = cell(line, 'wh-line__sku', 'SKU');
            sku.textContent = item.sku || 'No SKU';

            const have = cell(line, 'wh-line__have', 'At source');
            const availableKnown = item.available_qty !== null && item.available_qty !== undefined;
            have.textContent = availableKnown ? String(item.available_qty) : 'Not counted';
            have.setAttribute('data-wh-have', '');

            const qtyCell = cell(line, null, 'Units to move');
            const label = document.createElement('label');
            label.className = 'x-sr';
            label.setAttribute('for', 'wh-line-' + i + '-qty');
            label.textContent = 'Units to move';
            qtyCell.appendChild(label);

            const qty = document.createElement('input');
            qty.id = 'wh-line-' + i + '-qty';
            qty.className = 'x-inline-input x-inline-input--num';
            qty.type = 'number';
            qty.min = '1';
            qty.step = '1';
            qty.required = true;
            qty.name = 'items[' + i + '][quantity]';
            qty.value = item.quantity !== undefined && item.quantity !== null ? String(item.quantity) : '1';
            qty.setAttribute('data-wh-qty', '');
            if (availableKnown) qty.dataset.available = String(item.available_qty);
            qtyCell.appendChild(qty);

            checkQty(qty);
        },
    });

    host.addEventListener('input', function (e) {
        const input = e.target.closest('[data-wh-qty]');
        if (!input) return;
        checkQty(input);
        retotal(list.lines());
    });

    productSearch({
        input: searchInput,
        list: searchList,
        url: url,
        warehouseSelect: fromSelect,
        onPick: list.add,
        groupAdd: true,
        haveLabel: 'have',
    });

    if (fromSelect) {
        fromSelect.addEventListener('change', function () {
            const whId = fromSelect.value;
            if (!whId) return;

            list.lines().forEach(function (line) {
                const pid = line.dataset.productId;
                const pov = line.dataset.povId;
                const haveCell = line.querySelector('[data-wh-have]');
                const qty = line.querySelector('[data-wh-qty]');

                fetch(url + '?term=__id:' + encodeURIComponent(pid) + '&warehouse_id=' + encodeURIComponent(whId), {
                    headers: { Accept: 'application/json' },
                })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (data) {
                        const found = data.find(function (d) {
                            return Number(d.product_id) === Number(pid)
                                && Number(d.product_option_value_id) === Number(pov);
                        });
                        const available = found && found.available_qty !== null && found.available_qty !== undefined
                            ? found.available_qty
                            : null;

                        if (haveCell) haveCell.textContent = available !== null ? String(available) : 'Not counted';
                        if (qty) {
                            if (available !== null) {
                                qty.dataset.available = String(available);
                            } else {
                                delete qty.dataset.available;
                            }
                            checkQty(qty);
                        }
                    })
                    .catch(function () {
                        if (haveCell) haveCell.textContent = 'Not counted';
                    });
            });
        });
    }

    function lockPairs() {
        if (!fromSelect || !toSelect) return;
        Array.from(fromSelect.options).forEach(function (o) { o.disabled = false; });
        Array.from(toSelect.options).forEach(function (o) { o.disabled = false; });

        if (fromSelect.value) {
            const opt = toSelect.querySelector('option[value="' + CSS.escape(fromSelect.value) + '"]');
            if (opt) opt.disabled = true;
        }
        if (toSelect.value) {
            const opt = fromSelect.querySelector('option[value="' + CSS.escape(toSelect.value) + '"]');
            if (opt) opt.disabled = true;
        }
    }

    if (fromSelect) fromSelect.addEventListener('change', lockPairs);
    if (toSelect) toSelect.addEventListener('change', lockPairs);
    lockPairs();

}

function initAdjustForm() {
    const form = document.querySelector('[data-wh-adjust-form]');
    if (!form) return;

    const host = form.querySelector('[data-wh-lines]');
    const empty = form.querySelector('[data-wh-empty]');
    const warehouse = form.querySelector('[data-wh-warehouse]');
    const searchInput = form.querySelector('[data-wh-search]');
    const searchList = form.querySelector('[data-wh-results]');
    const url = form.dataset.productSearchUrl;

    if (!host) return;

    const list = lineList({
        host: host,
        empty: empty,
        build: function (line, i, item) {
            const name = cell(line, 'wh-line__name');
            hidden(name, 'items[' + i + '][product_id]', item.product_id);
            hidden(name, 'items[' + i + '][product_option_value_id]', item.product_option_value_id || 0);
            const b = document.createElement('b');
            b.textContent = item.name || '';
            name.appendChild(b);

            const sku = cell(line, 'wh-line__sku', 'SKU');
            sku.textContent = item.sku || 'No SKU';

            const current = item.available_qty !== null && item.available_qty !== undefined
                ? Number(item.available_qty)
                : 0;

            const have = cell(line, 'wh-line__have', 'System says');
            have.textContent = String(current);

            const qtyCell = cell(line, null, 'Counted');
            const label = document.createElement('label');
            label.className = 'x-sr';
            label.setAttribute('for', 'wh-adj-' + i + '-qty');
            label.textContent = 'Counted quantity';
            qtyCell.appendChild(label);

            const qty = document.createElement('input');
            qty.id = 'wh-adj-' + i + '-qty';
            qty.className = 'x-inline-input x-inline-input--num';
            qty.type = 'number';
            qty.step = '1';
            qty.required = true;
            qty.name = 'items[' + i + '][quantity]';
            qty.value = String(current);
            qty.setAttribute('data-wh-qty', '');
            qtyCell.appendChild(qty);
        },
    });

    function syncSearchState() {
        if (!searchInput) return;
        const ready = Boolean(warehouse && warehouse.value);
        searchInput.disabled = !ready;
        searchInput.placeholder = ready
            ? 'Search by product name or SKU'
            : 'Choose a location first';
    }

    if (warehouse) warehouse.addEventListener('change', syncSearchState);
    syncSearchState();

    productSearch({
        input: searchInput,
        list: searchList,
        url: url,
        warehouseSelect: warehouse,
        onPick: list.add,
        groupAdd: false,
        haveLabel: 'now',
    });

}

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || !form.matches) return;

    const isTransfer = form.matches('[data-wh-transfer-form]');
    const isAdjust = form.matches('[data-wh-adjust-form]');
    if (!isTransfer && !isAdjust) return;

    const flash = document.querySelector('[data-wh-flash]');
    const lines = form.querySelectorAll('[data-wh-line]');

    function refuse(message) {
        e.preventDefault();
        e.stopPropagation();
        setFlash(flash, message);
    }

    setFlash(flash, '');

    if (isAdjust) {
        const warehouse = form.querySelector('[data-wh-warehouse]');
        if (warehouse && !warehouse.value) {
            refuse('Choose the location you counted before saving.');
            return;
        }
        if (lines.length === 0) {
            refuse('Add at least one product before saving.');
        }
        return;
    }

    if (lines.length === 0) {
        refuse('Add at least one product before saving this transfer.');
        return;
    }

    const from = form.querySelector('[data-wh-from]');
    const to = form.querySelector('[data-wh-to]');
    if (from && to && from.value && from.value === to.value) {
        refuse('Source and destination have to be different locations.');
        return;
    }

    const over = Array.from(form.querySelectorAll('[data-wh-qty]')).some(function (input) {
        const available = parseInt(input.dataset.available, 10);
        const qty = parseInt(input.value, 10) || 0;
        return Number.isFinite(available) && qty > available;
    });

    if (over) {
        refuse('One or more lines move more units than the source location holds. Completing that transfer would be refused.');
    }
}, true);

document.addEventListener('DOMContentLoaded', function () {
    initTransferForm();
    initAdjustForm();
});
