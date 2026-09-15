/*
 * Purchasing pages (layouts.shell).
 *
 * Covers the purchase-order create/edit header typeahead, the line-item
 * editor on the edit page, the receive page's fill helpers, and the reorder
 * suggestions selection counter. The product-vendor matrix has its own
 * module (product-vendors.js) because it talks to a different set of
 * endpoints and shares nothing but the typeahead shape.
 *
 * Two things this module exists to replace:
 *
 *   1. window.setupTypeahead. That function was defined by an inline script
 *      inside layouts/app.blade.php (a protected file at the time, deleted
 *      in Round F Task 4). Nothing defines it on layouts.shell, so the vendor
 *      pickers on these pages would silently do nothing after the move, and
 *      nothing defines it anywhere now. The implementation below is
 *      the same contract (debounced fetch, hidden id input, mousedown
 *      selection) rendered into the `po-ta` markup this design system uses.
 *
 *   2. The inline <script> blocks the old views carried. Endpoints now come
 *      off data- attributes written by route() in Blade, so no URL is
 *      hardcoded in JS and a routing change cannot silently break a page.
 *
 * Everything builds DOM with createElement/textContent. No innerHTML is
 * used anywhere in this file, so a product or vendor name containing markup
 * is shown as text and cannot inject anything.
 */

/* ------------------------------------------------------------------
   Shared helpers
   ------------------------------------------------------------------ */

/** Format a number the way every money figure on these pages reads. */
function fmt(n) {
    return (Number.isFinite(n) ? n : 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Vendor typeahead.
 *
 * `input` is what the user types in, `hidden` carries the chosen id (the
 * field the controller validates), `list` is the popup. Typing clears the
 * hidden id, so a half-typed name can never submit a stale selection.
 */
function vendorTypeahead(input, hidden, list, url, onPick) {
    if (!input || !hidden || !list) return;

    let timer = null;
    let lastQuery = null;

    function close() {
        list.classList.remove('is-open');
        list.textContent = '';
    }

    function render(items) {
        list.textContent = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'po-ta__empty';
            empty.textContent = 'No vendors found';
            list.appendChild(empty);
            list.classList.add('is-open');
            return;
        }

        items.forEach(function (v) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'po-ta__item';
            // display_name is what VendorController::lookup() builds
            // ("Name | Nickname"); fall back to the plain name.
            btn.textContent = v.display_name || v.name || '';
            // mousedown, not click: the input's blur would close the list
            // before a click could land on it.
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                input.value = v.name + (v.nickname ? ' (' + v.nickname + ')' : '');
                hidden.value = v.id;
                close();
                if (typeof onPick === 'function') onPick(v);
            });
            list.appendChild(btn);
        });

        list.classList.add('is-open');
    }

    function search(q) {
        if (q === lastQuery) return;
        lastQuery = q;
        fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q), {
            headers: { Accept: 'application/json' },
        })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(render)
            .catch(function () { close(); });
    }

    input.addEventListener('input', function () {
        hidden.value = '';
        clearTimeout(timer);
        const q = input.value.trim();
        timer = setTimeout(function () { search(q); }, 200);
    });

    // Focus shows the full list, matching the old showAllOnFocus option.
    input.addEventListener('focus', function () {
        lastQuery = null;
        search(input.value.trim());
    });

    document.addEventListener('click', function (e) {
        if (e.target !== input && !list.contains(e.target)) close();
    });
}

/* ------------------------------------------------------------------
   Purchase order form: header (create and edit)
   ------------------------------------------------------------------ */

function initPoHeader(form) {
    const vendorInput = form.querySelector('[data-po-vendor-input]');
    const vendorHidden = form.querySelector('[data-po-vendor-id]');
    const vendorList = form.querySelector('[data-po-vendor-list]');
    const currency = form.querySelector('[data-po-currency]');
    const rate = form.querySelector('[data-po-rate]');

    vendorTypeahead(vendorInput, vendorHidden, vendorList, form.dataset.vendorLookupUrl, function (v) {
        // Picking a vendor moves the order onto that vendor's currency, and
        // the currency's own change handler then fills the rate.
        if (v.currency_code && currency) {
            currency.value = v.currency_code;
            currency.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    if (currency && rate) {
        currency.addEventListener('change', function () {
            const opt = currency.options[currency.selectedIndex];
            if (opt && opt.dataset.rate) rate.value = opt.dataset.rate;
        });
    }
}

/* ------------------------------------------------------------------
   Purchase order form: line items (edit only)
   ------------------------------------------------------------------ */

function initPoLines(form) {
    const body = form.querySelector('[data-po-lines-body]');
    if (!body) return;

    const emptyMsg = form.querySelector('[data-po-lines-empty]');
    const subtotalEl = form.querySelector('[data-po-subtotal]');
    const discountEl = form.querySelector('[data-po-discount]');
    const totalEl = form.querySelector('[data-po-total]');
    const baseRow = form.querySelector('[data-po-base-row]');
    const baseTotalEl = form.querySelector('[data-po-base-total]');
    const currency = form.querySelector('[data-po-currency]');
    const rate = form.querySelector('[data-po-rate]');
    const defaultCode = form.dataset.defaultCode || '';

    let nextIndex = parseInt(form.dataset.nextIndex || '0', 10) || 0;

    /* ---- totals ---- */

    function recalc() {
        let subtotal = 0;
        let discount = 0;

        body.querySelectorAll('[data-po-line]').forEach(function (line) {
            const qty = parseFloat(line.querySelector('[data-po-qty]').value) || 0;
            const price = parseFloat(line.querySelector('[data-po-price]').value) || 0;
            const disc = parseFloat(line.querySelector('[data-po-disc]').value) || 0;
            const discTypeEl = line.querySelector('[data-po-disc-type]');
            const discType = discTypeEl ? discTypeEl.value : 'percent';

            const gross = qty * price;
            // Same arithmetic PurchaseOrderController::update() applies
            // server-side, so the figure on screen is the figure stored.
            const lineDisc = discType === 'fixed' ? disc : gross * (disc / 100);

            line.querySelector('[data-po-line-total]').textContent = fmt(gross - lineDisc);
            subtotal += gross;
            discount += lineDisc;
        });

        const total = subtotal - discount;
        if (subtotalEl) subtotalEl.textContent = fmt(subtotal);
        if (discountEl) discountEl.textContent = fmt(discount);
        if (totalEl) totalEl.textContent = fmt(total);

        if (baseRow && baseTotalEl) {
            const isForeign = currency && currency.value !== defaultCode;
            baseRow.hidden = !isForeign;
            if (isForeign) {
                const r = parseFloat(rate ? rate.value : '1') || 1;
                baseTotalEl.textContent = fmt(total * r);
            }
        }

        if (emptyMsg) {
            emptyMsg.hidden = body.querySelectorAll('[data-po-line]').length > 0;
        }
    }

    /* ---- building a line ----
       This mirrors extensions/purchasing/views/purchase_orders/_line.blade.php
       field for field. Both sides share the `po-line` grid and the same
       data-label values, so a change to one has to be made in the other. */

    function field(tag, className, attrs) {
        const el = document.createElement(tag);
        el.className = className;
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'value') { el.value = attrs[k]; return; }
            el.setAttribute(k, attrs[k]);
        });
        return el;
    }

    function cell(label, child) {
        const span = document.createElement('span');
        if (label) span.setAttribute('data-label', label);
        if (child) span.appendChild(child);
        return span;
    }

    function buildLine(product) {
        const i = nextIndex++;
        const p = product || {};
        const vp = p.vendor_price || null;

        const line = document.createElement('div');
        line.className = 'po-line';
        line.setAttribute('data-po-line', '');

        const grip = field('span', 'po-line__grip', { 'data-po-grip': '', 'aria-hidden': 'true' });
        grip.textContent = '⋮⋮';
        line.appendChild(grip);

        const thumb = field('span', 'po-thumb' + (p.image ? '' : ' po-thumb--empty'), {});
        if (p.image) {
            const img = document.createElement('img');
            img.src = p.image;
            img.alt = '';
            img.loading = 'lazy';
            thumb.appendChild(img);
        }
        line.appendChild(thumb);

        // Description cell also carries the two hidden identity fields.
        const desc = cell('Description', null);
        desc.appendChild(field('input', '', {
            type: 'hidden', name: 'items[' + i + '][product_id]', value: p.product_id || '',
        }));
        desc.appendChild(field('input', '', {
            type: 'hidden', name: 'items[' + i + '][product_option_value_id]', value: p.product_option_value_id || '',
        }));
        desc.appendChild(field('input', 'po-in', {
            name: 'items[' + i + '][description]', maxlength: '255',
            placeholder: 'What you are buying', value: p.name || '',
        }));
        line.appendChild(desc);

        line.appendChild(cell('Our SKU', field('input', 'po-in po-in--num', {
            name: 'items[' + i + '][sku]', maxlength: '128', value: p.sku || '',
        })));

        line.appendChild(cell('Vendor SKU', field('input', 'po-in po-in--num', {
            name: 'items[' + i + '][vendor_sku]', maxlength: '128',
            value: (vp && vp.vendor_sku) ? vp.vendor_sku : '',
        })));

        line.appendChild(cell('Qty', field('input', 'po-in po-in--num', {
            'data-po-qty': '', type: 'number', min: '1', step: '1',
            name: 'items[' + i + '][quantity]',
            value: (vp && Number(vp.min_qty) > 0) ? String(parseInt(vp.min_qty, 10)) : '1',
        })));

        line.appendChild(cell('Unit price', field('input', 'po-in po-in--num', {
            'data-po-price': '', type: 'number', step: '0.0001', min: '0',
            name: 'items[' + i + '][unit_price]',
            value: (vp && vp.unit_price != null) ? vp.unit_price : '0',
        })));

        const discCell = document.createElement('span');
        discCell.className = 'po-line__disc';
        discCell.setAttribute('data-label', 'Discount');
        discCell.appendChild(field('input', 'po-in po-in--num', {
            'data-po-disc': '', type: 'number', step: '0.01', min: '0',
            name: 'items[' + i + '][discount_percent]', value: '0',
        }));
        const discType = field('select', 'po-in po-in--unit', {
            'data-po-disc-type': '', name: 'items[' + i + '][discount_type]',
        });
        const optPct = document.createElement('option');
        optPct.value = 'percent';
        optPct.textContent = '%';
        const optAmt = document.createElement('option');
        optAmt.value = 'fixed';
        optAmt.textContent = 'Amt';
        discType.appendChild(optPct);
        discType.appendChild(optAmt);
        discCell.appendChild(discType);
        line.appendChild(discCell);

        const lineTotal = field('span', 'po-line__total', {
            'data-po-line-total': '', 'data-label': 'Line total',
        });
        lineTotal.textContent = '0.00';
        line.appendChild(lineTotal);

        const remove = field('button', 'po-line__x', {
            type: 'button', 'data-po-remove': '', 'aria-label': 'Remove this line',
        });
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '13');
        svg.setAttribute('height', '13');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6');
        svg.appendChild(path);
        remove.appendChild(svg);
        line.appendChild(remove);

        body.appendChild(line);
        recalc();
    }

    /* ---- product search ---- */

    const search = form.querySelector('[data-po-product-search]');
    const results = form.querySelector('[data-po-product-list]');
    const searchUrl = form.dataset.productSearchUrl;

    if (search && results && searchUrl) {
        let searchTimer = null;

        function closeResults() {
            results.classList.remove('is-open');
            results.textContent = '';
        }

        function resultButton(text, sku, extraClass) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'po-ac__item' + (extraClass ? ' ' + extraClass : '');
            btn.appendChild(document.createTextNode(text));
            if (sku) {
                const s = document.createElement('span');
                s.className = 'po-ac__sku';
                s.textContent = '  ' + sku;
                btn.appendChild(s);
            }
            return btn;
        }

        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const q = search.value.trim();
            if (q.length < 2) { closeResults(); return; }

            searchTimer = setTimeout(function () {
                const vendorId = (form.querySelector('[data-po-vendor-id]') || {}).value || 0;
                fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&vendor_id=' + encodeURIComponent(vendorId), {
                    headers: { Accept: 'application/json' },
                })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (data) {
                        results.textContent = '';

                        if (!data.length) {
                            const none = document.createElement('div');
                            none.className = 'po-ac__empty';
                            none.textContent = 'No products match that';
                            results.appendChild(none);
                            results.classList.add('is-open');
                            return;
                        }

                        // A product with variations arrives as one row per
                        // option value. The first row of each group gets an
                        // "add all" line above it, and its siblings are
                        // indented under it.
                        const seen = {};
                        data.forEach(function (p) {
                            if (p.has_options && p.option_group && !seen[p.option_group]) {
                                const group = data.filter(function (v) { return v.option_group === p.option_group; });
                                seen[p.option_group] = group;

                                const baseName = String(p.name || '').split(' — ')[0];
                                const all = resultButton(
                                    'Add all ' + p.option_count + ' variations of ' + baseName,
                                    null,
                                    'po-ac__item--all'
                                );
                                all.addEventListener('click', function () {
                                    group.forEach(buildLine);
                                    closeResults();
                                    search.value = '';
                                });
                                results.appendChild(all);
                            }

                            const btn = resultButton(
                                p.name || '',
                                p.sku || null,
                                p.has_options ? 'po-ac__item--child' : null
                            );
                            btn.addEventListener('click', function () {
                                buildLine(p);
                                closeResults();
                                search.value = '';
                            });
                            results.appendChild(btn);
                        });

                        results.classList.add('is-open');
                    })
                    .catch(closeResults);
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (e.target !== search && !results.contains(e.target)) closeResults();
        });
    }

    const freetext = form.querySelector('[data-po-add-freetext]');
    if (freetext) {
        freetext.addEventListener('click', function () { buildLine(null); });
    }

    /* ---- edit and remove ---- */

    body.addEventListener('input', recalc);
    body.addEventListener('change', recalc);

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-po-remove]');
        if (!btn) return;
        const line = btn.closest('[data-po-line]');
        if (line) { line.remove(); reindex(); recalc(); }
    });

    if (currency) currency.addEventListener('change', recalc);
    if (rate) rate.addEventListener('input', recalc);

    /* ---- drag to reorder ----
       The handle arms the row for dragging on pointerdown and disarms it
       afterwards, so the rest of the row (every one of which is a text
       input) stays selectable with the mouse. */

    let dragging = null;

    body.addEventListener('pointerdown', function (e) {
        const grip = e.target.closest('[data-po-grip]');
        if (!grip) return;
        const line = grip.closest('[data-po-line]');
        if (!line) return;
        line.draggable = true;
        dragging = line;
    });

    document.addEventListener('pointerup', function () {
        // A click on the handle that never became a drag has to give the
        // draggable flag back, or the row stays draggable from anywhere.
        if (dragging && !dragging.classList.contains('is-dragging')) {
            dragging.draggable = false;
            dragging = null;
        }
    });

    body.addEventListener('dragstart', function (e) {
        if (!dragging) { e.preventDefault(); return; }
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        dragging.classList.add('is-dragging');
    });

    body.addEventListener('dragover', function (e) {
        if (!dragging) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('[data-po-line]');
        if (!target || target === dragging || !body.contains(target)) return;
        const rect = target.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            body.insertBefore(dragging, target);
        } else {
            body.insertBefore(dragging, target.nextSibling);
        }
    });

    body.addEventListener('dragend', function () {
        if (!dragging) return;
        dragging.classList.remove('is-dragging');
        dragging.draggable = false;
        dragging = null;
        reindex();
    });

    /* The array index in every field name has to match the row's position
       after a move, or PHP would collapse two rows onto one key. */
    function reindex() {
        const lines = body.querySelectorAll('[data-po-line]');
        lines.forEach(function (line, i) {
            line.querySelectorAll('input[name], select[name]').forEach(function (el) {
                el.name = el.name.replace(/items\[\d+\]/, 'items[' + i + ']');
            });
        });
        nextIndex = lines.length;
    }

    recalc();
}

/* ------------------------------------------------------------------
   Receive page
   ------------------------------------------------------------------ */

function initReceive(root) {
    // "All" on a line fills that line's remaining quantity.
    root.addEventListener('click', function (e) {
        const fill = e.target.closest('[data-po-fill]');
        if (fill) {
            const input = fill.closest('[data-po-rcv-in]').querySelector('[data-po-rcv-qty]');
            if (input) {
                input.value = fill.dataset.poFill;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return;
        }

        // "Fill every line" only fills; it does not submit. The old page
        // filled and submitted in one click, which put a stock-and-cost
        // write one button press away with nothing shown in between. The
        // operator now sees the quantities it filled before confirming.
        const fillAll = e.target.closest('[data-po-fill-all]');
        if (fillAll) {
            root.querySelectorAll('[data-po-fill]').forEach(function (btn) {
                const input = btn.closest('[data-po-rcv-in]').querySelector('[data-po-rcv-qty]');
                if (input) input.value = btn.dataset.poFill;
            });
            root.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    // Running total of what is about to be received, so the confirm dialog
    // is not the first time the operator sees a number.
    const tally = root.querySelector('[data-po-rcv-tally]');
    if (!tally) return;

    function retally() {
        let n = 0;
        root.querySelectorAll('[data-po-rcv-qty]').forEach(function (input) {
            n += parseInt(input.value, 10) || 0;
        });
        tally.textContent = n === 1 ? '1 unit to receive' : n.toLocaleString() + ' units to receive';
    }

    root.addEventListener('input', retally);
    retally();
}

/* ------------------------------------------------------------------
   Reorder suggestions
   ------------------------------------------------------------------ */

function initReorder(root) {
    const all = root.querySelector('[data-po-sug-all]');
    const count = root.querySelector('[data-po-sug-count]');

    function boxes() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-po-sug-check]'));
    }

    function update() {
        const list = boxes();
        const checked = list.filter(function (b) { return b.checked; }).length;

        if (count) {
            count.textContent = checked === 1 ? '1 product selected' : checked + ' products selected';
        }
        if (all) {
            all.checked = list.length > 0 && checked === list.length;
            all.indeterminate = checked > 0 && checked < list.length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes().forEach(function (b) { b.checked = all.checked; });
            update();
        });
    }

    root.addEventListener('change', function (e) {
        if (e.target.matches('[data-po-sug-check]')) update();
    });

    update();
}

/* ------------------------------------------------------------------
   Boot
   ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-po-form]').forEach(function (form) {
        initPoHeader(form);
        if (form.hasAttribute('data-po-lines-form')) initPoLines(form);
    });

    const receive = document.querySelector('[data-po-receive]');
    if (receive) initReceive(receive);

    const reorder = document.querySelector('[data-po-reorder]');
    if (reorder) initReorder(reorder);
});
