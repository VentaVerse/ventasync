/* ============================================================================
 * Sales order form: /sales/orders/create and /sales/orders/{id}/edit.
 * ----------------------------------------------------------------------------
 * The behaviour is the one both pages already had, lifted out of two nearly
 * identical inline <script> blocks and reduced to one module. Nothing about
 * the posted shape changed: the same `products[i][...]` hidden inputs, the
 * same `currency_code` / `currency_rate` / `currency_id` / `total` fields, the
 * same "payment same as shipping" copy-on-submit.
 *
 * THE CURRENCY RULE, which everything here turns on
 * ------------------------------------------------
 * `currencies.exchange_rate` (and an order's stored `currency_value`) is
 * "units of the DEFAULT currency per 1 unit of this currency". PHP, the
 * default, is 1.0; USD is about 61.6. So:
 *
 *     order-currency figure = default-currency figure / rate
 *     default-currency figure = order-currency figure * rate
 *
 * `basePrice` / `baseCost` in the state below are ALWAYS the default-currency
 * figure, because that is what the server stores (OrderCurrencyService's
 * storage rule) and what the product catalog holds. Everything on screen and
 * everything posted is the order-currency figure derived from it, so switching
 * currency re-derives rather than re-converting, and the source number never
 * drifts.
 *
 * Two things this module deliberately does that the inline scripts did not:
 *
 *   1. The rate hint names the DEFAULT currency. The old line read
 *      `code + ' 1 = ' + selectedSymbol + ' ' + rate`, which rendered
 *      "USD 1 = $ 61.61" - a sentence that says one dollar is worth sixty-one
 *      dollars. The default currency's code and symbol are now handed in on
 *      the root element and the hint reads "1 USD = PHP 61.61".
 *   2. A foreign order shows the default-currency figure alongside the
 *      order-currency one on every line and on the total, because that pair
 *      is what actually gets stored (price/total and foreign_price/
 *      foreign_total). An operator typing $100 should be able to see the
 *      PHP number the row will carry before saving it.
 *
 * Every node is built with createElement + textContent. Nothing here writes
 * innerHTML, so a product name carrying markup is text and cannot inject.
 * ========================================================================= */
(function () {
    'use strict';

    const root = document.querySelector('[data-of-root]');
    if (!root) return;

    const form = document.getElementById('of-form');
    if (!form) return;

    const defaultCode = root.dataset.defaultCode || '';
    const defaultSymbol = root.dataset.defaultSymbol || '';
    const searchUrl = root.dataset.searchUrl || '';

    // ---------------------------------------------------------------- helpers
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function money(symbol, amount) {
        return symbol + ' ' + Number(amount || 0).toFixed(2);
    }

    // --------------------------------------------------------------- currency
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

    /** Default-currency amount -> order-currency amount. */
    function toOrder(base) {
        const cur = selected();
        if (!cur.rate) return base;
        return base / cur.rate;
    }

    /**
     * The unit price actually posted, rounded once.
     *
     * The price field carries two decimals, so 6800 / 58.25 posts as 116.74,
     * not 116.7381. Every figure derived from it has to use the SAME rounded
     * number or the screen and the database disagree: the unrounded product
     * showed a line total of 350.21 while the server, given 116.74, stored
     * 350.22. Rounding once here and deriving the line total, the
     * default-currency figure and the order total from it means what is on
     * screen is exactly what gets written.
     */
    function unitPrice(item) {
        return Math.round(toOrder(item.basePrice) * 100) / 100;
    }

    function syncRate(preserve) {
        const cur = selected();

        codeHidden.value = cur.code;
        idHidden.value = cur.id;

        if (cur.isDefault) {
            // OrderCurrencyService::resolve() forces rate = 1 for the default
            // currency and discards any override, so the field reflects that
            // instead of offering a number the server will throw away.
            rateInput.value = 1;
        } else if (!preserve) {
            // The operator just switched currency: seed with that currency's
            // own stored rate rather than whatever the previous one left.
            rateInput.value = parseFloat(currencySelect.options[currencySelect.selectedIndex].dataset.rate) || 1;
        }

        rateInput.readOnly = cur.isDefault;

        rateHint.textContent = cur.isDefault
            ? 'The default currency. Its rate is always 1, and this order stores one figure per amount.'
            : '1 ' + cur.code + ' = ' + money(defaultSymbol, rateInput.value) + '. Each amount is stored twice: '
              + 'as typed in ' + cur.code + ', and converted to ' + defaultCode + '.';
    }

    currencySelect.addEventListener('change', function () {
        syncRate(false);
        render();
    });
    rateInput.addEventListener('input', function () {
        syncRate(true);
        render();
    });

    // Preserve whatever the page rendered into the field (the order's own
    // stored rate on edit, the feed rate on create) instead of clobbering it.
    syncRate(true);

    // ------------------------------------------------- payment same as shipping
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

    // ------------------------------------------------------------- line items
    // <x-ui.table> owns the <tbody>, so the empty row's parent is the handle.
    // Captured once, before the first render detaches that row.
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

            // Product, and the hidden inputs that ARE the wire.
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
            tr.appendChild(tdName);

            const tdVar = el('td', 'of-col-var x-cell-muted',
                item.option_value ? item.option_name + ': ' + item.option_value : 'None');
            tdVar.dataset.label = 'Variation';
            tr.appendChild(tdVar);

            const tdSku = el('td', 'of-col-sku x-num x-cell-muted', item.sku || item.model || 'None');
            tdSku.dataset.label = 'SKU';
            tr.appendChild(tdSku);

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
            tdQty.dataset.label = 'Quantity';
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
            const lineTotal = el('span', 'of-line__total x-num', money(cur.symbol, price * item.qty));
            tdTotal.appendChild(lineTotal);
            if (!cur.isDefault) {
                tdTotal.appendChild(el('span', 'of-line__base x-num',
                    money(defaultSymbol, price * item.qty * cur.rate)));
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
            // The operator edited the ORDER-currency figure. Convert it back
            // so basePrice stays the rate-independent source of truth.
            lines[idx].basePrice = typed * cur.rate;

            const row = target.closest('tr');
            const priceField = row.querySelector('input[name="products[' + idx + '][price]"]');
            if (priceField) priceField.value = typed.toFixed(2);
            row.querySelector('.of-line__total').textContent = money(cur.symbol, typed * lines[idx].qty);
            const base = row.querySelector('.of-line__base');
            if (base) base.textContent = money(defaultSymbol, typed * lines[idx].qty * cur.rate);
            recalc();
        }

        if (target.classList.contains('of-line__qty')) {
            const idx = parseInt(target.dataset.idx, 10);
            lines[idx].qty = parseInt(target.value, 10) || 1;
            const price = unitPrice(lines[idx]);

            const row = target.closest('tr');
            const qtyField = row.querySelector('input[name="products[' + idx + '][quantity]"]');
            if (qtyField) qtyField.value = lines[idx].qty;
            row.querySelector('.of-line__total').textContent = money(cur.symbol, price * lines[idx].qty);
            const base = row.querySelector('.of-line__base');
            if (base) base.textContent = money(defaultSymbol, price * lines[idx].qty * cur.rate);
            recalc();
        }
    });

    body.addEventListener('click', function (ev) {
        const remove = ev.target.closest('.of-line__x');
        if (!remove) return;
        lines.splice(parseInt(remove.dataset.idx, 10), 1);
        render();
    });

    // ----------------------------------------------------------- product picker
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

        // Same product, same SKU, same variation is the same line: add units
        // rather than a duplicate row.
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
