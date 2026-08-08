/*
 * Warehousing pages (layouts.shell).
 *
 * Drives the two line editors in this section, which are the same
 * machine with different columns:
 *
 *   - the transfer form (create and edit): search a product, add a
 *     line, type how many units to move, and see how many the source
 *     location actually holds;
 *   - the stock adjustment form: search a product, add a line, and
 *     type the counted quantity that should replace what the system
 *     believes.
 *
 * Both post `items[i][product_id]`, `items[i][product_option_value_id]`
 * and `items[i][quantity]`, which is exactly what
 * WarehouseTransferController::store()/update() and
 * WarehouseInventoryController::adjustStore() validate. The indices are
 * renumbered on every removal so the arrays stay contiguous.
 *
 * Two things this module exists to replace, both of which would fail
 * silently after the move to layouts.shell:
 *
 *   1. window.showFlashError. It writes into a #js-flash-container that
 *      only layouts.app renders, so on the shell every client-side
 *      guard on these forms (no lines added, source equals destination,
 *      quantity over stock) called a function that did nothing and let
 *      the submit proceed. The guards now write into the page's own
 *      .wh-flash region with textContent.
 *
 *   2. The inline <script> blocks the old views carried, with the
 *      search endpoint written into them by Blade. The endpoint now
 *      arrives on a data- attribute built by route(), so no URL shape
 *      is assumed in JS.
 *
 * Everything builds DOM with createElement/textContent. There is no
 * innerHTML in this file, so a product name containing markup is shown
 * as text and cannot inject anything.
 */

/* ------------------------------------------------------------------
   Shared helpers
   ------------------------------------------------------------------ */

/**
 * Show a single sentence in the page's flash region, or clear it.
 * Same note grammar the server-rendered flash uses.
 */
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

/**
 * The product search popup, shared by both editors.
 *
 * `onPick` receives one API result. The "add all N variations" row is
 * only offered on the transfer form, where moving a whole product's
 * variations between locations in one go is the common case; the
 * adjustment form counts one shelf label at a time.
 */
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

        // mousedown, not click: the input's blur closes the list before
        // a click could land on it.
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

/**
 * The line list both editors keep.
 *
 * `build` renders one line's cells into a container element; the rest
 * (duplicate detection, index renumbering, the empty notice) is the
 * same for both forms.
 */
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

        // Already listed: put the cursor on its quantity rather than
        // adding a second line the controller would treat as a second
        // movement of the same shelf label.
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

    // Blade-rendered lines (transfer edit) are already in the host.
    renumber();
    syncEmpty();

    return { add: add, lines: lines, syncEmpty: syncEmpty };
}

/** One labelled cell. */
function cell(line, className, label) {
    const el = document.createElement('span');
    if (className) el.className = className;
    if (label) el.setAttribute('data-label', label);
    line.appendChild(el);
    return el;
}

/** A hidden field carrying an id the controller validates. */
function hidden(parent, name, value) {
    const el = document.createElement('input');
    el.type = 'hidden';
    el.name = name;
    el.value = value;
    parent.appendChild(el);
    return el;
}

/* ------------------------------------------------------------------
   Transfer form (create and edit)
   ------------------------------------------------------------------ */

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

    /* Changing the source location changes what is on the shelf, so
       every listed line's on-hand figure is looked up again. */
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

    /* The two location selects cannot name the same place: the
       controller validates `different:from_warehouse_id`, so the option
       already chosen on one side is disabled on the other. */
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

/* ------------------------------------------------------------------
   Stock adjustment form
   ------------------------------------------------------------------ */

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

    /* The search only knows a location's counts once a location is
       chosen, so choosing one is the first step and the search stays
       shut until it happens. */
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

/* ------------------------------------------------------------------
   Submit guards

   Registered at module scope, in the capture phase, on purpose.
   confirm-modal.js also listens for submit on document in the capture
   phase; it intercepts a [data-confirm] form, shows the dialog and
   re-submits on confirmation. Whichever listener is registered first
   runs first, and confirm-modal registers inside its own
   DOMContentLoaded handler. A guard registered the same way would run
   only AFTER the operator had already confirmed, so a form with
   nothing in it would ask "are you sure?" before saying there was
   nothing to save.

   Everything checked here is read straight off the DOM, so the guard
   needs no state from the initialisers below.
   ------------------------------------------------------------------ */
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
