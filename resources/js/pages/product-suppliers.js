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

export default function initProductSuppliers(root) {
    const flash = root.querySelector('[data-po-mx-flash]');
    const list = root.querySelector('[data-po-mx-list]');

    const createUrl = root.dataset.createUrl;
    const bulkUrl = root.dataset.bulkUrl;
    const bulkDeleteUrl = root.dataset.bulkDeleteUrl;
    const rowUrlTemplate = root.dataset.rowUrl;
    const supplierLookupUrl = root.dataset.supplierLookupUrl;
    const defaultCurrency = root.dataset.defaultCurrency || '';

    let currencies = [];
    let variationValues = [];
    try { currencies = JSON.parse(root.dataset.currencies || '[]'); } catch (e) { currencies = []; }
    try { variationValues = JSON.parse(root.dataset.variationValues || '[]'); } catch (e) { variationValues = []; }

    const descUrlTemplate = root.dataset.descUrl || '';

    function descPanel(id) {
        return root.querySelector('[data-po-mx-desc="' + CSS.escape(String(id)) + '"]');
    }

    function rich(textarea) {
        return (window.jQuery && window.jQuery(textarea).data('summernote')) ? window.jQuery(textarea) : null;
    }

    function readDesc(textarea) {
        const editor = rich(textarea);
        if (!editor) return textarea.value;
        return editor.summernote('isEmpty') ? '' : editor.summernote('code');
    }

    function writeDesc(textarea, html) {
        const editor = rich(textarea);
        if (editor) editor.summernote('code', html);
        textarea.value = html;
    }

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

    function supplierTypeahead(input, hidden, listEl, onPick) {
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
                empty.textContent = 'No suppliers found';
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
            fetch(supplierLookupUrl + (supplierLookupUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q), {
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
        title.textContent = 'No suppliers linked yet';
        const desc = el('p', 'x-empty__desc');
        desc.textContent = 'Link a supplier below to record what you pay for this product.';
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
                    supplier_sku: row.querySelector('[data-po-mx-sku]').value || null,
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

            const descToggle = e.target.closest('[data-po-mx-desc-toggle]');
            if (descToggle) {
                const panel = descPanel(descToggle.closest('[data-po-mx-row]').dataset.poMxRow);
                if (panel) {
                    panel.hidden = !panel.hidden;
                    descToggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                }
                return;
            }

            const descSave = e.target.closest('[data-po-mx-desc-save]');
            if (descSave) {
                const panel = descSave.closest('[data-po-mx-desc]');
                const id = panel.dataset.poMxDesc;
                const row = list.querySelector('[data-po-mx-row="' + CSS.escape(id) + '"]');
                descSave.disabled = true;
                clearSay();
                json(descUrlTemplate.replace(ROW_ID_TOKEN, encodeURIComponent(id)), 'PUT', {
                    supplier_description: readDesc(panel.querySelector('[data-po-mx-desc-input]')),
                })
                    .then(function (res) {
                        descSave.disabled = false;
                        if (!res.ok) {
                            say(apiError(res, 'That description could not be saved.'), 'fail');
                            return;
                        }
                        const preview = row ? row.querySelector('[data-po-mx-desc-preview]') : null;
                        if (preview) {
                            preview.textContent = res.data.preview ? 'Supplier description: ' + res.data.preview : 'Add a supplier description';
                        }
                        say('Description saved.', 'ok');
                    })
                    .catch(function () {
                        descSave.disabled = false;
                        say('That description could not be saved. Check your connection and try again.', 'fail');
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
                        const panel = descPanel(row.dataset.poMxRow);
                        if (panel) panel.remove();
                        row.remove();
                        retallyGroup(group);
                    });
                });
                return;
            }

            const removeSupplier = e.target.closest('[data-po-mx-remove-supplier]');
            if (removeSupplier) {
                const group = groupOf(removeSupplier);
                const name = group.querySelector('.po-mx-group__name').textContent;
                window.confirmModal('Remove every price for ' + name + '?').then(function (ok) {
                    if (!ok) return;
                    clearSay();
                    json(bulkDeleteUrl, 'DELETE', { supplier_id: parseInt(group.dataset.poMxGroup, 10) }).then(function (res) {
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
            const copy = e.target.closest('[data-po-mx-desc-copy]');
            if (copy) {
                const source = descPanel(copy.value);
                const target = copy.closest('[data-po-mx-desc]').querySelector('[data-po-mx-desc-input]');
                if (source && target) writeDesc(target, readDesc(source.querySelector('[data-po-mx-desc-input]')));
                copy.value = '';
                return;
            }

            const row = e.target.closest('[data-po-mx-row]');
            if (!row) return;
            const save = row.querySelector('[data-po-mx-save]');
            if (save) save.hidden = false;
        });
    }

    const addBtn = root.querySelector('[data-po-mx-add]');
    if (addBtn) {
        const supplierInput = root.querySelector('.po-mx-simple [data-po-supplier-input]');
        const supplierHidden = root.querySelector('.po-mx-simple [data-po-supplier-id]');
        const supplierList = root.querySelector('.po-mx-simple [data-po-supplier-list]');
        const skuField = root.querySelector('[data-po-mx-new-sku]');
        const qtyField = root.querySelector('[data-po-mx-new-qty]');
        const curField = root.querySelector('[data-po-mx-new-currency]');
        const priceField = root.querySelector('[data-po-mx-new-price]');

        supplierTypeahead(supplierInput, supplierHidden, supplierList, function (v) {
            if (v.currency_code && curField) curField.value = v.currency_code;
        });

        addBtn.addEventListener('click', function () {
            if (!supplierHidden.value) {
                say('Pick a supplier from the list first.', 'fail');
                return;
            }

            clearSay();
            addBtn.disabled = true;

            json(createUrl, 'POST', {
                supplier_id: parseInt(supplierHidden.value, 10),
                supplier_sku: skuField.value || null,
                min_qty: parseInt(qtyField.value, 10) || 1,
                currency_code: curField.value,
                unit_price: parseFloat(priceField.value) || 0,
            })
                .then(function (res) {
                    addBtn.disabled = false;
                    if (!res.ok) {
                        say(apiError(res, 'That supplier could not be linked.'), 'fail');
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    addBtn.disabled = false;
                    say('That supplier could not be linked. Check your connection and try again.', 'fail');
                });
        });
    }

    const optRows = root.querySelector('[data-po-mx-opt-rows]');
    if (optRows) {
        function buildVariationRows() {
            optRows.textContent = '';

            variationValues.forEach(function (ov) {
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

                const supplierCell = el('span', 'po-ta', { 'data-label': 'Supplier' });
                const supplierInput = el('input', 'po-in', {
                    type: 'text', autocomplete: 'off', placeholder: 'Search suppliers',
                    'aria-label': 'Supplier for ' + ov.label,
                });
                const supplierHidden = el('input', '', { type: 'hidden' });
                const supplierList = el('div', 'po-ta__list');
                supplierCell.appendChild(supplierInput);
                supplierCell.appendChild(supplierHidden);
                supplierCell.appendChild(supplierList);
                row.appendChild(supplierCell);
                row.__supplier = supplierHidden;
                row.__supplierInput = supplierInput;

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

                const skuCell = el('span', '', { 'data-label': 'Supplier SKU' });
                const sku = el('input', 'po-in po-in--num', {
                    maxlength: '128', value: ov.sku || '',
                    'aria-label': 'Supplier SKU for ' + ov.label,
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

                supplierTypeahead(supplierInput, supplierHidden, supplierList, function (v) {
                    if (v.currency_code) cur.value = v.currency_code;
                    markSupplierBreaks();
                });
            });

            markSupplierBreaks();
        }

        function markSupplierBreaks() {
            let previous = null;
            optRows.querySelectorAll('[data-po-mx-opt-row]').forEach(function (row) {
                const vid = row.__supplier.value || null;
                row.classList.toggle('is-supplier-break', previous !== null && !!vid && vid !== previous);
                if (vid) previous = vid;
            });
        }

        const copyFirst = root.querySelector('[data-po-mx-copy-first]');
        if (copyFirst) {
            copyFirst.addEventListener('click', function () {
                const rows = Array.prototype.slice.call(optRows.querySelectorAll('[data-po-mx-opt-row]'));
                if (rows.length < 2) return;

                const first = rows[0];
                if (!first.__supplier.value) {
                    say('Pick a supplier in the first row before copying it down.', 'fail');
                    return;
                }

                clearSay();
                rows.slice(1).forEach(function (row) {
                    row.__supplier.value = first.__supplier.value;
                    row.__supplierInput.value = first.__supplierInput.value;
                    row.__currency.value = first.__currency.value;
                    row.__qty.value = first.__qty.value;
                });
                markSupplierBreaks();
            });
        }

        const bulkBtn = root.querySelector('[data-po-mx-add-bulk]');
        const status = root.querySelector('[data-po-mx-status]');

        if (bulkBtn) {
            bulkBtn.addEventListener('click', function () {
                const variations = [];

                optRows.querySelectorAll('[data-po-mx-opt-row]').forEach(function (row) {
                    if (!row.__supplier.value) return;
                    variations.push({
                        product_variation_value_id: parseInt(row.dataset.poMxOptRow, 10),
                        supplier_id: parseInt(row.__supplier.value, 10),
                        currency_code: row.__currency.value,
                        min_qty: parseInt(row.__qty.value, 10) || 1,
                        supplier_sku: row.__sku.value || null,
                        unit_price: parseFloat(row.__price.value) || 0,
                    });
                });

                if (!variations.length) {
                    say('Pick a supplier for at least one variation.', 'fail');
                    return;
                }

                clearSay();
                bulkBtn.disabled = true;

                json(bulkUrl, 'POST', { variations: variations })
                    .then(function (res) {
                        bulkBtn.disabled = false;
                        if (!res.ok) {
                            say(apiError(res, 'Those suppliers could not be linked.'), 'fail');
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
                        say('Those suppliers could not be linked. Check your connection and try again.', 'fail');
                    });
            });
        }

        buildVariationRows();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-po-matrix]');
    if (root) initProductSuppliers(root);
});
