(function () {
    'use strict';

    const root = document.querySelector('[data-of-root]');
    if (!root) return;

    const form = document.getElementById('of-form');
    if (!form) return;

    const defaultCode = root.dataset.defaultCode || '';
    const defaultSymbol = root.dataset.defaultSymbol || '';
    const searchUrl = root.dataset.searchUrl || '';

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function money(symbol, amount) {
        return symbol + Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Round to cents before multiplying again, or the display disagrees with the server's rounding.
    function roundCents(amount) {
        return Math.round((Number(amount) || 0) * 100) / 100;
    }

    const currencySelect = document.getElementById('of-currency');
    const codeHidden = document.getElementById('of-currency-code');
    const idHidden = document.getElementById('of-currency-id');
    const rateInput = document.getElementById('of-rate');
    const rateHint = document.getElementById('of-rate-hint');

    function selected() {
        const opt = currencySelect.options[currencySelect.selectedIndex];
        return {
            id: opt.value,
            code: opt.dataset.code,
            symbol: opt.dataset.symbol,
            isDefault: opt.dataset.isDefault === '1',
            rate: parseFloat(rateInput.value) || parseFloat(opt.dataset.rate) || 1,
        };
    }

    function toOrder(base) {
        const cur = selected();
        if (!cur.rate) return base;
        return base / cur.rate;
    }

    // Use the posted, 2dp unit price for every derived figure so the screen matches what is stored.
    function unitPrice(item) {
        return Math.round(toOrder(item.basePrice) * 100) / 100;
    }

    function syncRate(preserve) {
        const cur = selected();

        codeHidden.value = cur.code;
        idHidden.value = cur.id;

        if (cur.isDefault) {
            rateInput.value = 1;
        } else if (!preserve) {
            rateInput.value = parseFloat(currencySelect.options[currencySelect.selectedIndex].dataset.rate) || 1;
        }

        rateInput.readOnly = cur.isDefault;

        const effective = selected();

        rateHint.textContent = effective.isDefault
            ? 'The default currency. Its rate is always 1, and this order stores one figure per amount.'
            : '1 ' + effective.code + ' = ' + money(defaultSymbol, effective.rate) + '. Each amount is stored twice: '
              + 'as typed in ' + effective.code + ', and converted to ' + defaultCode + '.';
    }

    currencySelect.addEventListener('change', function () {
        syncRate(false);
        render();
    });
    rateInput.addEventListener('input', function () {
        syncRate(true);
        render();
    });

    syncRate(true);

    const sameBox = document.getElementById('of-same');
    const payBlock = document.getElementById('of-payment');

    function syncPayment() {
        payBlock.hidden = sameBox.checked;
    }
    sameBox.addEventListener('change', syncPayment);
    syncPayment();

    form.addEventListener('submit', function () {
        if (!sameBox.checked) return;
        document.querySelectorAll('.of-ship').forEach(function (shipInput) {
            const field = shipInput.dataset.field;
            if (!field) return;
            const payInput = document.querySelector('.of-pay[data-field="' + field + '"]');
            if (payInput) payInput.value = shipInput.value;
        });
    });

    const emptyRow = document.getElementById('of-empty');
    const body = emptyRow.parentNode;
    const totalOut = document.getElementById('of-total');
    const totalBaseOut = document.getElementById('of-total-base');
    const totalInput = document.getElementById('of-total-input');
    const countOut = document.getElementById('of-count');

    let lines = [];
    try {
        lines = JSON.parse(root.dataset.lines || '[]');
    } catch (e) {
        lines = [];
    }

    function recalc() {
        const cur = selected();
        let total = 0;
        lines.forEach(function (item) {
            total += unitPrice(item) * item.qty;
        });
        total = roundCents(total);

        totalOut.textContent = money(cur.symbol, total);
        totalInput.value = total.toFixed(2);

        if (totalBaseOut) {
            if (cur.isDefault) {
                totalBaseOut.hidden = true;
            } else {
                totalBaseOut.hidden = false;
                totalBaseOut.textContent = money(defaultSymbol, total * cur.rate);
            }
        }

        if (countOut) {
            const units = lines.reduce(function (n, item) { return n + item.qty; }, 0);
            countOut.textContent = lines.length === 0
                ? 'Nothing added yet'
                : lines.length + (lines.length === 1 ? ' line, ' : ' lines, ') + units + (units === 1 ? ' unit' : ' units');
        }
    }

    function hidden(idx, name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'products[' + idx + '][' + name + ']';
        input.value = value;
        return input;
    }

    function render() {
        const cur = selected();

        body.textContent = '';

        if (lines.length === 0) {
            body.appendChild(emptyRow);
            recalc();
            return;
        }

        lines.forEach(function (item, idx) {
            const price = unitPrice(item);
            const cost = Math.round(toOrder(item.baseCost) * 100) / 100;
            const label = item.option_value ? item.name + ' - ' + item.option_value : item.name;

            const tr = document.createElement('tr');

            const tdName = el('td', 'of-line__name');
            tdName.dataset.label = 'Product';
            tdName.appendChild(el('span', 'of-line__title', label));
            tdName.appendChild(el('span', 'of-line__id', '#' + item.product_id));

            [
                ['product_id', item.product_id],
                ['name', label],
                ['model', item.model],
                ['cost', cost.toFixed(2)],
                ['price', price.toFixed(2)],
                ['quantity', item.qty],
            ].forEach(function (pair) {
                tdName.appendChild(hidden(idx, pair[0], pair[1]));
            });

            if (item.option_value_id) {
                tdName.appendChild(hidden(idx, 'option_value_id', item.option_value_id));
                tdName.appendChild(hidden(idx, 'option_name', item.option_name || ''));
                tdName.appendChild(hidden(idx, 'option_value', item.option_value || ''));
            }
            const meta = el('span', 'of-line__meta');
            if (item.option_value) {
                meta.appendChild(el('span', 'of-line__var', item.option_name + ': ' + item.option_value));
            }
            meta.appendChild(el('span', 'of-line__sku x-num', item.sku || item.model || 'No SKU'));
            tdName.appendChild(meta);
            tr.appendChild(tdName);

            const tdPrice = el('td', 'of-col-price');
            tdPrice.dataset.label = 'Unit price';
            const priceInput = document.createElement('input');
            priceInput.type = 'number';
            priceInput.className = 'x-input fm-input--num of-line__price';
            priceInput.dataset.idx = idx;
            priceInput.value = price.toFixed(2);
            priceInput.step = '0.01';
            priceInput.min = '0';
            priceInput.setAttribute('aria-label', 'Unit price for ' + label + ' in ' + cur.code);
            tdPrice.appendChild(priceInput);
            tr.appendChild(tdPrice);

            const tdQty = el('td', 'of-col-qty');
            tdQty.dataset.label = 'Qty';
            const qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.className = 'x-input fm-input--num of-line__qty';
            qtyInput.dataset.idx = idx;
            qtyInput.value = item.qty;
            qtyInput.min = '1';
            qtyInput.setAttribute('aria-label', 'Quantity of ' + label);
            tdQty.appendChild(qtyInput);
            tr.appendChild(tdQty);

            const tdTotal = el('td', 'of-col-total x-td-num');
            tdTotal.dataset.label = 'Line total';
            const lineAmount = roundCents(price * item.qty);
            const lineTotal = el('span', 'of-line__total x-num', money(cur.symbol, lineAmount));
            tdTotal.appendChild(lineTotal);
            if (!cur.isDefault) {
                tdTotal.appendChild(el('span', 'of-line__base x-num',
                    money(defaultSymbol, lineAmount * cur.rate)));
            }
            tr.appendChild(tdTotal);

            const tdX = el('td', 'x-td-actions');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'of-line__x';
            remove.dataset.idx = idx;
            remove.setAttribute('aria-label', 'Remove ' + label);
            remove.appendChild(icon('M18 6 6 18M6 6l12 12'));
            tdX.appendChild(remove);
            tr.appendChild(tdX);

            body.appendChild(tr);
        });

        recalc();
    }

    function icon(d) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '14');
        svg.setAttribute('height', '14');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.75');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', d);
        svg.appendChild(path);
        return svg;
    }

    body.addEventListener('input', function (ev) {
        const target = ev.target;
        const cur = selected();

        if (target.classList.contains('of-line__price')) {
            const idx = parseInt(target.dataset.idx, 10);
            const typed = parseFloat(target.value) || 0;
            lines[idx].basePrice = typed * cur.rate;

            const row = target.closest('tr');
            const priceField = row.querySelector('input[name="products[' + idx + '][price]"]');
            if (priceField) priceField.value = typed.toFixed(2);
            const lineAmount = roundCents(typed * lines[idx].qty);
            row.querySelector('.of-line__total').textContent = money(cur.symbol, lineAmount);
            const base = row.querySelector('.of-line__base');
            if (base) base.textContent = money(defaultSymbol, lineAmount * cur.rate);
            recalc();
        }

        if (target.classList.contains('of-line__qty')) {
            const idx = parseInt(target.dataset.idx, 10);
            lines[idx].qty = parseInt(target.value, 10) || 1;
            const price = unitPrice(lines[idx]);

            const row = target.closest('tr');
            const qtyField = row.querySelector('input[name="products[' + idx + '][quantity]"]');
            if (qtyField) qtyField.value = lines[idx].qty;
            const lineAmount = roundCents(price * lines[idx].qty);
            row.querySelector('.of-line__total').textContent = money(cur.symbol, lineAmount);
            const base = row.querySelector('.of-line__base');
            if (base) base.textContent = money(defaultSymbol, lineAmount * cur.rate);
            recalc();
        }
    });

    body.addEventListener('click', function (ev) {
        const remove = ev.target.closest('.of-line__x');
        if (!remove) return;
        lines.splice(parseInt(remove.dataset.idx, 10), 1);
        render();
    });

    const picker = document.getElementById('of-picker');
    const openBtn = document.getElementById('of-add');
    const searchInput = document.getElementById('of-search');
    const results = document.getElementById('of-results');
    const detail = document.getElementById('of-detail');
    const detailName = document.getElementById('of-detail-name');
    const detailSku = document.getElementById('of-detail-sku');
    const detailPrice = document.getElementById('of-detail-price');
    const optionsWrap = document.getElementById('of-options');
    const optionsList = document.getElementById('of-options-list');
    const qtyInput = document.getElementById('of-pick-qty');
    const addBtn = document.getElementById('of-pick-add');
    const message = document.getElementById('of-pick-msg');
    const addedOut = document.getElementById('of-pick-added');

    let picked = null;
    let timer = null;
    let addedCount = 0;

    function say(text) {
        message.textContent = text || '';
    }

    function openPicker() {
        picker.classList.add('active');
        searchInput.value = '';
        results.textContent = '';
        results.hidden = true;
        detail.hidden = true;
        picked = null;
        addedCount = 0;
        addedOut.hidden = true;
        say('');
        window.setTimeout(function () { searchInput.focus(); }, 60);
    }

    function closePicker() {
        picker.classList.remove('active');
        picked = null;
        if (timer) window.clearTimeout(timer);
        openBtn.focus();
    }

    openBtn.addEventListener('click', openPicker);
    picker.addEventListener('click', function (ev) {
        if (ev.target === picker || ev.target.closest('[data-of-close]')) closePicker();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && picker.classList.contains('active')) closePicker();
    });

    function optionPrice(product, option) {
        const abs = parseFloat(option.absolute_price) || 0;
        if (abs > 0) return abs;
        const base = parseFloat(product.price) || 0;
        const modifier = parseFloat(option.option_price) || 0;
        return option.price_prefix === '-' ? base - modifier : base + modifier;
    }

    function optionCost(product, option) {
        const abs = parseFloat(option.absolute_cost) || 0;
        if (abs > 0) return abs;
        const base = parseFloat(product.cost) || 0;
        const modifier = parseFloat(option.option_cost) || 0;
        return option.cost_prefix === '-' ? base - modifier : base + modifier;
    }

    function showDetail(product) {
        const cur = selected();
        picked = product;

        detailName.textContent = product.name;
        detailSku.textContent = product.sku || product.model || '';
        detailPrice.textContent = 'Catalog price ' + money(cur.symbol, toOrder(parseFloat(product.price)));

        optionsList.textContent = '';
        qtyInput.value = 1;

        if (product.options && product.options.length > 0) {
            optionsWrap.hidden = false;
            product.options.forEach(function (option, i) {
                const label = el('label', 'of-opt');
                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'of_option_pick';
                radio.className = 'of-opt__radio';
                radio.value = i;
                if (i === 0) radio.checked = true;

                const text = el('span', 'of-opt__text');
                text.appendChild(el('span', 'of-opt__name', option.option_name + ': ' + option.value_name));
                text.appendChild(el('span', 'of-opt__meta',
                    money(cur.symbol, toOrder(optionPrice(product, option)))
                    + (option.option_sku ? '  ' + option.option_sku : '')
                    + '  ' + option.option_qty + ' in stock'));

                label.appendChild(radio);
                label.appendChild(text);
                optionsList.appendChild(label);
            });
        } else {
            optionsWrap.hidden = true;
        }

        detail.hidden = false;
    }

    function runSearch() {
        const term = searchInput.value.trim();
        if (term.length < 2) {
            results.hidden = true;
            results.textContent = '';
            return;
        }

        detail.hidden = true;
        picked = null;
        say('Searching');

        fetch(searchUrl + '?q=' + encodeURIComponent(term), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const items = data.items || [];
                const cur = selected();

                results.textContent = '';
                say('');

                if (items.length === 0) {
                    results.appendChild(el('p', 'of-res__none', 'Nothing matches that.'));
                    results.hidden = false;
                    return;
                }

                items.forEach(function (product) {
                    const row = document.createElement('button');
                    row.type = 'button';
                    row.className = 'of-res';

                    const main = el('span', 'of-res__main');
                    main.appendChild(el('span', 'of-res__name', product.name));
                    main.appendChild(el('span', 'of-res__sku', product.sku || product.model || 'No SKU'));
                    row.appendChild(main);

                    row.appendChild(el('span', 'of-res__price x-num',
                        money(cur.symbol, toOrder(parseFloat(product.price)))));
                    row.appendChild(el('span', 'of-res__stock x-num', product.quantity + ' in stock'));

                    if (product.options && product.options.length > 0) {
                        row.appendChild(el('span', 'of-res__opts',
                            product.options.length + (product.options.length === 1 ? ' variation' : ' variations')));
                    } else {
                        row.appendChild(el('span', 'of-res__opts', ''));
                    }

                    row.addEventListener('click', function () { showDetail(product); });
                    results.appendChild(row);
                });

                results.hidden = false;
            })
            .catch(function () {
                say('The product search did not answer. Try again.');
            });
    }

    searchInput.addEventListener('input', function () {
        if (timer) window.clearTimeout(timer);
        timer = window.setTimeout(runSearch, 300);
    });
    searchInput.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        if (timer) window.clearTimeout(timer);
        runSearch();
    });

    addBtn.addEventListener('click', function () {
        if (!picked) {
            say('Pick a product from the results first.');
            return;
        }

        let qty = parseInt(qtyInput.value, 10) || 1;
        if (qty < 1) qty = 1;

        const product = picked;
        const item = {
            product_id: parseInt(product.product_id, 10),
            name: product.name || '',
            sku: product.sku || '',
            model: product.model || '',
            basePrice: parseFloat(product.price) || 0,
            baseCost: parseFloat(product.cost) || 0,
            qty: qty,
            option_value_id: null,
            option_name: '',
            option_value: '',
        };

        if (product.options && product.options.length > 0) {
            const checked = optionsList.querySelector('input[name="of_option_pick"]:checked');
            if (!checked) {
                say('Pick a variation.');
                return;
            }
            const option = product.options[parseInt(checked.value, 10)];
            item.basePrice = optionPrice(product, option);
            item.baseCost = optionCost(product, option);
            item.sku = option.option_sku || item.sku;
            item.option_value_id = option.product_option_value_id || option.combination_id || null;
            item.option_name = option.option_name;
            item.option_value = option.value_name;
        }

        const existing = lines.findIndex(function (line) {
            return line.product_id === item.product_id
                && line.sku === item.sku
                && line.option_value === item.option_value;
        });

        if (existing >= 0) {
            lines[existing].qty += item.qty;
        } else {
            lines.push(item);
        }

        render();

        addedCount += 1;
        addedOut.hidden = false;
        addedOut.textContent = addedCount === 1 ? '1 line added' : addedCount + ' lines added';
        detail.hidden = true;
        picked = null;
        qtyInput.value = 1;
        searchInput.focus();
    });

    render();
})();


document.addEventListener('DOMContentLoaded', function () {
    const select = document.querySelector('.of-page [data-status-select]');
    const note = document.querySelector('.of-page [data-status-note]');
    if (!select || !note) return;
    const currentSubtract = select.getAttribute('data-current-subtract') === '1';

    function unitsOnPage() {
        let n = 0;
        document.querySelectorAll('.of-line__qty').forEach(function (i) { n += parseInt(i.value, 10) || 0; });
        return n;
    }

    function describe() {
        const opt = select.options[select.selectedIndex];
        const willSubtract = opt && opt.getAttribute('data-subtract') === '1';
        if (!opt || willSubtract === currentSubtract) {
            note.hidden = true;
            note.textContent = '';
            return;
        }
        const units = unitsOnPage();
        if (units === 0) { note.hidden = true; note.textContent = ''; return; }
        note.hidden = false;
        note.textContent = 'Saving with ' + opt.textContent.trim() + (willSubtract ? ' deducts ' : ' restores ') + units + (units === 1 ? ' unit' : ' units') + ' of stock.';
    }

    select.addEventListener('change', describe);
    document.addEventListener('input', function (e) { if (e.target && e.target.classList.contains('of-line__qty')) describe(); });
    describe();

    const addBtn = document.getElementById('of-add');
    const hasLines = document.querySelector('.of-line__qty');
    if (addBtn && !hasLines && !document.querySelector('.fm-note--fail')) addBtn.focus();
});
