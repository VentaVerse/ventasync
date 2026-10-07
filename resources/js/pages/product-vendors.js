const ROW_ID_TOKEN = '__ID__';

function csrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

function json(url, method, body) {
    return fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
        return r
            .json()
            .catch(function () { return {}; })
            .then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
    });
}

function el(tag, className, attrs) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    Object.keys(attrs || {}).forEach(function (k) {
        if (k === 'value') { node.value = attrs[k]; return; }
        node.setAttribute(k, attrs[k]);
    });
    return node;
}

export default function initProductVendors(root) {
    const flash = root.querySelector('[data-po-mx-flash]');
    const list = root.querySelector('[data-po-mx-list]');

    const createUrl = root.dataset.createUrl;
    const bulkUrl = root.dataset.bulkUrl;
    const bulkDeleteUrl = root.dataset.bulkDeleteUrl;
    const rowUrlTemplate = root.dataset.rowUrl;
    const vendorLookupUrl = root.dataset.vendorLookupUrl;
    const defaultCurrency = root.dataset.defaultCurrency || '';

    let currencies = [];
    let optionValues = [];
    try { currencies = JSON.parse(root.dataset.currencies || '[]'); } catch (e) { currencies = []; }
    try { optionValues = JSON.parse(root.dataset.optionValues || '[]'); } catch (e) { optionValues = []; }

    function rowUrl(id) {
        return rowUrlTemplate.replace(ROW_ID_TOKEN, encodeURIComponent(id));
    }

    function say(message, kind) {
        if (!flash) return;
        flash.textContent = '';
        const note = el('div', 'fm-note fm-note--' + (kind === 'ok' ? 'ok' : 'fail'));
        const body = el('div', 'fm-note__body');
        body.textContent = message;
        note.appendChild(body);
        flash.appendChild(note);
        note.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearSay() {
        if (flash) flash.textContent = '';
    }

    function apiError(res, fallback) {
        return (res.data && (res.data.error || res.data.message)) || fallback;
    }

    function vendorTypeahead(input, hidden, listEl, onPick) {
        if (!input || !hidden || !listEl) return;

        let timer = null;

        function close() {
            listEl.classList.remove('is-open');
            listEl.textContent = '';
        }

        function render(items) {
            listEl.textContent = '';

            if (!items.length) {
                const empty = el('div', 'po-ta__empty');
                empty.textContent = 'No vendors found';
                listEl.appendChild(empty);
                listEl.classList.add('is-open');
                return;
            }

            items.forEach(function (v) {
                const btn = el('button', 'po-ta__item', { type: 'button' });
                btn.textContent = v.display_name || v.name || '';
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    input.value = v.name + (v.nickname ? ' (' + v.nickname + ')' : '');
                    hidden.value = v.id;
                    close();
                    if (typeof onPick === 'function') onPick(v);
                });
                listEl.appendChild(btn);
            });

            listEl.classList.add('is-open');
        }

        function search(q) {
            fetch(vendorLookupUrl + (vendorLookupUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q), {
                headers: { Accept: 'application/json' },
            })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(render)
                .catch(close);
        }

        input.addEventListener('input', function () {
            hidden.value = '';
            clearTimeout(timer);
            const q = input.value.trim();
            timer = setTimeout(function () { search(q); }, 200);
        });

        input.addEventListener('focus', function () { search(input.value.trim()); });

        document.addEventListener('click', function (e) {
            if (e.target !== input && !listEl.contains(e.target)) close();
        });
    }

    function groupOf(node) {
        return node.closest('[data-po-mx-group]');
    }

    function retallyGroup(group) {
        if (!group) return;
        const rows = group.querySelectorAll('[data-po-mx-row]');
        const count = group.querySelector('[data-po-mx-count]');
        if (count) count.textContent = rows.length;
        if (rows.length === 0) group.remove();
        retallyPage();
    }

    function retallyPage() {
        if (!list) return;
        if (list.querySelector('[data-po-mx-group]')) return;
        if (list.querySelector('[data-po-mx-empty]')) return;

        const empty = el('div', 'x-empty', { 'data-po-mx-empty': '' });
        const title = el('p', 'x-empty__title');
        title.textContent = 'No vendors linked yet';
        const desc = el('p', 'x-empty__desc');
        desc.textContent = 'Link a vendor below to record what you pay for this product.';
        empty.appendChild(title);
        empty.appendChild(desc);
        list.appendChild(empty);
    }

    if (list) {
        list.addEventListener('click', function (e) {
            const toggle = e.target.closest('[data-po-mx-toggle]');
            if (toggle) {
                groupOf(toggle).classList.toggle('is-open');
                return;
            }

            const save = e.target.closest('[data-po-mx-save]');
            if (save) {
                const row = save.closest('[data-po-mx-row]');
                const id = row.dataset.poMxRow;
                row.classList.add('is-saving');
                clearSay();

                json(rowUrl(id), 'PUT', {
                    vendor_sku: row.querySelector('[data-po-mx-sku]').value || null,
                    min_qty: parseInt(row.querySelector('[data-po-mx-qty]').value, 10) || 1,
                    currency_code: row.querySelector('[data-po-mx-currency]').value,
                    unit_price: parseFloat(row.querySelector('[data-po-mx-price]').value) || 0,
                })
                    .then(function (res) {
                        row.classList.remove('is-saving');
                        if (!res.ok) {
                            say(apiError(res, 'That price could not be saved.'), 'fail');
                            return;
                        }
                        save.hidden = true;
                        row.classList.add('is-saved');
                        setTimeout(function () { row.classList.remove('is-saved'); }, 1500);
                    })
                    .catch(function () {
                        row.classList.remove('is-saving');
                        say('That price could not be saved. Check your connection and try again.', 'fail');
                    });
                return;
            }

            const removeRow = e.target.closest('[data-po-mx-remove-row]');
            if (removeRow) {
                const row = removeRow.closest('[data-po-mx-row]');
                const group = groupOf(row);
                window.confirmModal('Remove this price?').then(function (ok) {
                    if (!ok) return;
                    clearSay();
                    json(rowUrl(row.dataset.poMxRow), 'DELETE', null).then(function (res) {
                        if (!res.ok) {
                            say(apiError(res, 'That price could not be removed.'), 'fail');
                            return;
                        }
                        row.remove();
                        retallyGroup(group);
                    });
                });
                return;
            }

            const removeVendor = e.target.closest('[data-po-mx-remove-vendor]');
            if (removeVendor) {
                const group = groupOf(removeVendor);
                const name = group.querySelector('.po-mx-group__name').textContent;
                window.confirmModal('Remove every price for ' + name + '?').then(function (ok) {
                    if (!ok) return;
                    clearSay();
                    json(bulkDeleteUrl, 'DELETE', { vendor_id: parseInt(group.dataset.poMxGroup, 10) }).then(function (res) {
                        if (!res.ok) {
                            say(apiError(res, 'Those prices could not be removed.'), 'fail');
                            return;
                        }
                        group.remove();
                        retallyPage();
                    });
                });
            }
        });

        list.addEventListener('input', function (e) {
            const row = e.target.closest('[data-po-mx-row]');
            if (!row) return;
            const save = row.querySelector('[data-po-mx-save]');
            if (save) save.hidden = false;
        });

        list.addEventListener('change', function (e) {
            const row = e.target.closest('[data-po-mx-row]');
            if (!row) return;
            const save = row.querySelector('[data-po-mx-save]');
            if (save) save.hidden = false;
        });
    }

    const addBtn = root.querySelector('[data-po-mx-add]');
    if (addBtn) {
        const vendorInput = root.querySelector('.po-mx-simple [data-po-vendor-input]');
        const vendorHidden = root.querySelector('.po-mx-simple [data-po-vendor-id]');
        const vendorList = root.querySelector('.po-mx-simple [data-po-vendor-list]');
        const skuField = root.querySelector('[data-po-mx-new-sku]');
        const qtyField = root.querySelector('[data-po-mx-new-qty]');
        const curField = root.querySelector('[data-po-mx-new-currency]');
        const priceField = root.querySelector('[data-po-mx-new-price]');

        vendorTypeahead(vendorInput, vendorHidden, vendorList, function (v) {
            if (v.currency_code && curField) curField.value = v.currency_code;
        });

        addBtn.addEventListener('click', function () {
            if (!vendorHidden.value) {
                say('Pick a vendor from the list first.', 'fail');
                return;
            }

            clearSay();
            addBtn.disabled = true;

            json(createUrl, 'POST', {
                vendor_id: parseInt(vendorHidden.value, 10),
                vendor_sku: skuField.value || null,
                min_qty: parseInt(qtyField.value, 10) || 1,
                currency_code: curField.value,
                unit_price: parseFloat(priceField.value) || 0,
            })
                .then(function (res) {
                    addBtn.disabled = false;
                    if (!res.ok) {
                        say(apiError(res, 'That vendor could not be linked.'), 'fail');
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    addBtn.disabled = false;
                    say('That vendor could not be linked. Check your connection and try again.', 'fail');
                });
        });
    }

    const optRows = root.querySelector('[data-po-mx-opt-rows]');
    if (optRows) {
        function buildOptionRows() {
            optRows.textContent = '';

            optionValues.forEach(function (ov) {
                const row = el('div', 'po-mx-opt-row', { 'data-po-mx-opt-row': ov.id });

                const what = el('span', 'po-mx-opt-row__what');
                const name = el('span', 'po-mx-opt-row__name');
                name.textContent = ov.label;
                what.appendChild(name);
                if (ov.sku) {
                    const sku = el('span', 'po-mx-opt-row__sku');
                    sku.textContent = ov.sku;
                    what.appendChild(sku);
                }
                row.appendChild(what);

                const vendorCell = el('span', 'po-ta', { 'data-label': 'Vendor' });
                const vendorInput = el('input', 'po-in', {
                    type: 'text', autocomplete: 'off', placeholder: 'Search vendors',
                    'aria-label': 'Vendor for ' + ov.label,
                });
                const vendorHidden = el('input', '', { type: 'hidden' });
                const vendorList = el('div', 'po-ta__list');
                vendorCell.appendChild(vendorInput);
                vendorCell.appendChild(vendorHidden);
                vendorCell.appendChild(vendorList);
                row.appendChild(vendorCell);
                row.__vendor = vendorHidden;
                row.__vendorInput = vendorInput;

                const curCell = el('span', '', { 'data-label': 'Currency' });
                const cur = el('select', 'po-in', { 'aria-label': 'Currency for ' + ov.label });
                currencies.forEach(function (code) {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = code;
                    if (code === defaultCurrency) opt.selected = true;
                    cur.appendChild(opt);
                });
                curCell.appendChild(cur);
                row.appendChild(curCell);
                row.__currency = cur;

                const qtyCell = el('span', '', { 'data-label': 'Min qty' });
                const qty = el('input', 'po-in po-in--num', {
                    type: 'number', min: '1', step: '1', value: '1',
                    'aria-label': 'Minimum quantity for ' + ov.label,
                });
                qtyCell.appendChild(qty);
                row.appendChild(qtyCell);
                row.__qty = qty;

                const skuCell = el('span', '', { 'data-label': 'Vendor SKU' });
                const sku = el('input', 'po-in po-in--num', {
                    maxlength: '128', value: ov.sku || '',
                    'aria-label': 'Vendor SKU for ' + ov.label,
                });
                skuCell.appendChild(sku);
                row.appendChild(skuCell);
                row.__sku = sku;

                const priceCell = el('span', '', { 'data-label': 'Unit price' });
                const price = el('input', 'po-in po-in--num', {
                    type: 'number', step: '0.0001', min: '0', value: '0',
                    'aria-label': 'Unit price for ' + ov.label,
                });
                priceCell.appendChild(price);
                row.appendChild(priceCell);
                row.__price = price;

                optRows.appendChild(row);

                vendorTypeahead(vendorInput, vendorHidden, vendorList, function (v) {
                    if (v.currency_code) cur.value = v.currency_code;
                    markVendorBreaks();
                });
            });

            markVendorBreaks();
        }

        function markVendorBreaks() {
            let previous = null;
            optRows.querySelectorAll('[data-po-mx-opt-row]').forEach(function (row) {
                const vid = row.__vendor.value || null;
                row.classList.toggle('is-vendor-break', previous !== null && !!vid && vid !== previous);
                if (vid) previous = vid;
            });
        }

        const copyFirst = root.querySelector('[data-po-mx-copy-first]');
        if (copyFirst) {
            copyFirst.addEventListener('click', function () {
                const rows = Array.prototype.slice.call(optRows.querySelectorAll('[data-po-mx-opt-row]'));
                if (rows.length < 2) return;

                const first = rows[0];
                if (!first.__vendor.value) {
                    say('Pick a vendor in the first row before copying it down.', 'fail');
                    return;
                }

                clearSay();
                rows.slice(1).forEach(function (row) {
                    row.__vendor.value = first.__vendor.value;
                    row.__vendorInput.value = first.__vendorInput.value;
                    row.__currency.value = first.__currency.value;
                    row.__qty.value = first.__qty.value;
                });
                markVendorBreaks();
            });
        }

        const bulkBtn = root.querySelector('[data-po-mx-add-bulk]');
        const status = root.querySelector('[data-po-mx-status]');

        if (bulkBtn) {
            bulkBtn.addEventListener('click', function () {
                const options = [];

                optRows.querySelectorAll('[data-po-mx-opt-row]').forEach(function (row) {
                    if (!row.__vendor.value) return;
                    options.push({
                        product_option_value_id: parseInt(row.dataset.poMxOptRow, 10),
                        vendor_id: parseInt(row.__vendor.value, 10),
                        currency_code: row.__currency.value,
                        min_qty: parseInt(row.__qty.value, 10) || 1,
                        vendor_sku: row.__sku.value || null,
                        unit_price: parseFloat(row.__price.value) || 0,
                    });
                });

                if (!options.length) {
                    say('Pick a vendor for at least one variation.', 'fail');
                    return;
                }

                clearSay();
                bulkBtn.disabled = true;

                json(bulkUrl, 'POST', { options: options })
                    .then(function (res) {
                        bulkBtn.disabled = false;
                        if (!res.ok) {
                            say(apiError(res, 'Those vendors could not be linked.'), 'fail');
                            return;
                        }

                        const added = Array.isArray(res.data) ? res.data.length : 0;
                        if (added === 0) {
                            if (status) {
                                status.textContent = 'Every one of those was already linked.';
                                status.hidden = false;
                            }
                            return;
                        }

                        window.location.reload();
                    })
                    .catch(function () {
                        bulkBtn.disabled = false;
                        say('Those vendors could not be linked. Check your connection and try again.', 'fail');
                    });
            });
        }

        buildOptionRows();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-po-matrix]');
    if (root) initProductVendors(root);
});
