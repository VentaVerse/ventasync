function pad(n) {
    return n < 10 ? '0' + n : '' + n;
}

function fmt(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function lastDay(year, month) {
    return new Date(year, month + 1, 0).getDate();
}

function presetRange(key) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let from;
    let to;

    switch (key) {
        case 'week': {
            from = new Date(today);
            const dow = from.getDay() === 0 ? 6 : from.getDay() - 1;
            from.setDate(from.getDate() - dow);
            to = new Date(today);
            break;
        }
        case 'month':
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            to = new Date(today);
            break;
        case 'quarter':
            from = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
            to = new Date(today);
            break;
        case 'year':
            from = new Date(today.getFullYear(), 0, 1);
            to = new Date(today);
            break;
        case 'last_week': {
            to = new Date(today);
            const dow = to.getDay() === 0 ? 6 : to.getDay() - 1;
            to.setDate(to.getDate() - dow - 1);
            from = new Date(to);
            from.setDate(from.getDate() - 6);
            break;
        }
        case 'last_month': {
            const year = today.getMonth() === 0 ? today.getFullYear() - 1 : today.getFullYear();
            const month = today.getMonth() === 0 ? 11 : today.getMonth() - 1;
            from = new Date(year, month, 1);
            to = new Date(year, month, lastDay(year, month));
            break;
        }
        case 'last_quarter': {
            let start = Math.floor(today.getMonth() / 3) * 3 - 3;
            let year = today.getFullYear();
            if (start < 0) {
                start += 12;
                year -= 1;
            }
            from = new Date(year, start, 1);
            to = new Date(year, start + 2, lastDay(year, start + 2));
            break;
        }
        case 'last_year':
            from = new Date(today.getFullYear() - 1, 0, 1);
            to = new Date(today.getFullYear() - 1, 11, 31);
            break;
        case 'all':
            from = new Date(2000, 0, 1);
            to = new Date(today);
            break;
        default:
            return null;
    }

    return { from: fmt(from), to: fmt(to) };
}

const PRESETS = ['week', 'month', 'quarter', 'year', 'last_week', 'last_month', 'last_quarter', 'last_year', 'all'];

function initRange() {
    const pills = Array.from(document.querySelectorAll('[data-range-preset-btn]'));
    const from = document.querySelector('[data-range-from]');
    const to = document.querySelector('[data-range-to]');
    if (!pills.length || !from || !to) return;

    function press(key) {
        pills.forEach(function (pill) {
            pill.setAttribute('aria-pressed', pill.getAttribute('data-range-preset-btn') === key ? 'true' : 'false');
        });
    }

    let pressed = 'custom';
    for (let i = 0; i < PRESETS.length; i += 1) {
        const range = presetRange(PRESETS[i]);
        if (range && range.from === from.value && range.to === to.value) {
            pressed = PRESETS[i];
            break;
        }
    }
    press(pressed);

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            const key = pill.getAttribute('data-range-preset-btn');
            const range = presetRange(key);
            if (range) {
                from.value = range.from;
                to.value = range.to;
            } else if (key === 'custom') {
                from.focus();
            }
            press(key);
            document.dispatchEvent(new CustomEvent('range:changed'));
        });
    });

    from.addEventListener('change', function () { press('custom'); document.dispatchEvent(new CustomEvent('range:changed')); });
    to.addEventListener('change', function () { press('custom'); document.dispatchEvent(new CustomEvent('range:changed')); });
}

function initPeriodChoices() {
    const choices = Array.from(document.querySelectorAll('[data-sold-months]'));
    const from = document.querySelector('[data-range-from]');
    const to = document.querySelector('[data-range-to]');
    if (!choices.length || !from || !to) return;

    function isHeading(el) {
        return el.classList.contains('rp-pick__group') || el.classList.contains('rp-pick__section');
    }

    function follow() {
        const first = from.value ? from.value.slice(0, 7) : '';
        const last = to.value ? to.value.slice(0, 7) : '';

        choices.forEach(function (choice) {
            const box = choice.querySelector('input[type=checkbox]');
            const sold = choice.getAttribute('data-sold-months').split(' ').some(function (m) {
                return (!first || m >= first) && (!last || m <= last);
            });
            choice.hidden = !sold && !(box && box.checked);
        });

        document.querySelectorAll('.rp-pick__list').forEach(function (list) {
            list.querySelectorAll('.rp-pick__group, .rp-pick__section').forEach(function (heading) {
                const group = heading.classList.contains('rp-pick__group');
                let any = false;
                for (let el = heading.nextElementSibling; el; el = el.nextElementSibling) {
                    if (group ? el.classList.contains('rp-pick__group') : isHeading(el)) break;
                    if (!isHeading(el) && !el.hidden) { any = true; break; }
                }
                heading.hidden = !any;
            });
        });
    }

    document.addEventListener('range:changed', follow);
}

function initGroups() {
    const toggles = document.querySelectorAll('[data-group-toggle]');
    if (!toggles.length) return;

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const key = toggle.getAttribute('data-group-toggle');
            const open = toggle.getAttribute('aria-expanded') === 'true';
            const rows = document.querySelectorAll('[data-group-row="' + CSS.escape(key) + '"]');

            rows.forEach(function (row) {
                if (open) {
                    row.setAttribute('hidden', '');
                } else {
                    row.removeAttribute('hidden');
                }
            });

            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
    });
}

function makeMoney(symbol) {
    return function (value) {
        const n = Number(value || 0);
        const sign = n < 0 ? '-' : '';
        return sign + symbol + Math.abs(n).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    };
}

function count(value) {
    return Number(value || 0).toLocaleString('en-US');
}

function cell(text, className) {
    const td = document.createElement('td');
    if (className) td.className = className;
    td.textContent = text;
    return td;
}

function subTable(headers, numFrom) {
    const table = document.createElement('table');
    table.className = 'rp-sub';

    const thead = document.createElement('thead');
    const tr = document.createElement('tr');
    headers.forEach(function (label, i) {
        const th = document.createElement('th');
        th.textContent = label;
        if (i >= numFrom) th.className = 'rp-num';
        tr.appendChild(th);
    });
    thead.appendChild(tr);
    table.appendChild(thead);
    table.appendChild(document.createElement('tbody'));

    return table;
}

function heading(text, note) {
    const el = document.createElement('div');
    el.className = 'rp-drill__heading';
    el.textContent = text;
    if (note) {
        const tip = document.createElement('span');
        tip.className = 'rp-drill__note';
        tip.title = note;
        tip.textContent = '(what counts as one customer?)';
        el.appendChild(tip);
    }
    return el;
}

function buildPanel(data, orderUrlTemplate) {
    const money = makeMoney((data.currency && data.currency.symbol) || '');
    const wrap = document.createElement('div');
    wrap.className = 'rp-drill';

    wrap.appendChild(heading(
        'By customer',
        'Grouped by customer name and channel. Marketplaces mask buyer names (L***o), so two different buyers can merge and one buyer can split across channels.'
    ));

    const byCustomer = subTable(['Customer', 'Channel', 'Orders', 'Units', 'Revenue', 'Net profit'], 2);
    const customerBody = byCustomer.querySelector('tbody');

    if (!data.by_customer.length) {
        const tr = document.createElement('tr');
        const td = cell('No customer data for this product in this window.', 'rp-drill__msg');
        td.colSpan = 6;
        tr.appendChild(td);
        customerBody.appendChild(tr);
    }

    data.by_customer.forEach(function (row) {
        const tr = document.createElement('tr');
        tr.appendChild(cell(row.customer));
        tr.appendChild(cell(row.source));
        tr.appendChild(cell(count(row.orders), 'rp-num'));
        tr.appendChild(cell(count(row.qty), 'rp-num'));
        tr.appendChild(cell(money(row.revenue), 'rp-num'));
        tr.appendChild(cell(money(row.net_profit), 'rp-num'));
        customerBody.appendChild(tr);
    });
    wrap.appendChild(byCustomer);

    let ordersLabel = 'Orders';
    if (data.total_lines > data.shown_lines) {
        ordersLabel = 'Orders, showing ' + count(data.shown_lines) + ' of ' + count(data.total_lines);
    }
    wrap.appendChild(heading(ordersLabel));

    const orders = subTable(
        ['Order', 'Date', 'Channel', 'Status', 'Customer', 'Units', 'Revenue', 'Cost of goods', 'Shipping', 'Fees', 'Net profit', 'Margin'],
        5
    );
    const ordersBody = orders.querySelector('tbody');

    data.orders.forEach(function (row) {
        const tr = document.createElement('tr');

        const idCell = document.createElement('td');
        const link = document.createElement('a');
        link.href = orderUrlTemplate.replace('__OID__', encodeURIComponent(row.order_id));
        link.className = 'rp-sub__link';
        link.textContent = row.order_id;
        idCell.appendChild(link);
        tr.appendChild(idCell);

        tr.appendChild(cell(row.date));
        tr.appendChild(cell(row.source));
        tr.appendChild(cell(row.status));
        tr.appendChild(cell(row.customer));
        tr.appendChild(cell(count(row.qty), 'rp-num'));
        tr.appendChild(cell(money(row.revenue), 'rp-num'));
        tr.appendChild(cell(money(row.cogs), 'rp-num'));
        tr.appendChild(cell(money(row.alloc_shipping), 'rp-num'));
        tr.appendChild(cell(money(row.alloc_fees), 'rp-num'));
        tr.appendChild(cell(money(row.net_profit), 'rp-num'));
        tr.appendChild(cell(row.margin + '%', 'rp-num'));

        ordersBody.appendChild(tr);
    });
    wrap.appendChild(orders);

    return wrap;
}

function message(text, isError) {
    const el = document.createElement('div');
    el.className = 'rp-drill__msg' + (isError ? ' rp-drill__msg--error' : '');
    el.textContent = text;
    return el;
}

function collapseRow(row) {
    const child = row.nextElementSibling;
    if (child && child.classList.contains('rp-drill-row')) {
        child.setAttribute('hidden', '');
    }
    row.querySelector('[data-drill-toggle]').setAttribute('aria-expanded', 'false');
    row.classList.remove('rp-open');
}

function expandRow(row, columnCount, orderUrlTemplate) {
    const toggle = row.querySelector('[data-drill-toggle]');
    toggle.setAttribute('aria-expanded', 'true');
    row.classList.add('rp-open');

    let child = row.nextElementSibling;
    if (child && child.classList.contains('rp-drill-row')) {
        child.removeAttribute('hidden');
        return;
    }

    child = document.createElement('tr');
    child.className = 'rp-drill-row';
    const holder = document.createElement('td');
    holder.colSpan = columnCount;
    holder.appendChild(message('Loading order lines...', false));
    child.appendChild(holder);
    row.parentNode.insertBefore(child, row.nextSibling);

    fetch(row.getAttribute('data-detail-url'), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (data) {
            holder.textContent = '';
            holder.appendChild(buildPanel(data, orderUrlTemplate));
        })
        .catch(function () {
            holder.textContent = '';
            holder.appendChild(message('Could not load the order lines for this product. Try again.', true));
        });
}

function initDrilldown() {
    const table = document.querySelector('[data-drill-table]');
    if (!table) return;

    const orderUrlTemplate = table.getAttribute('data-order-url');
    const columnCount = table.querySelectorAll('thead th').length;
    const rows = table.querySelectorAll('[data-drill-row]');

    rows.forEach(function (row) {
        const toggle = row.querySelector('[data-drill-toggle]');
        if (!toggle) return;

        toggle.addEventListener('click', function () {
            if (toggle.getAttribute('aria-expanded') === 'true') {
                collapseRow(row);
            } else {
                expandRow(row, columnCount, orderUrlTemplate);
            }
        });
    });

    const expandAll = document.querySelector('[data-drill-all]');
    if (!expandAll) return;

    expandAll.addEventListener('click', function () {
        const opening = expandAll.getAttribute('aria-expanded') !== 'true';

        rows.forEach(function (row) {
            const isOpen = row.querySelector('[data-drill-toggle]').getAttribute('aria-expanded') === 'true';
            if (opening && !isOpen) {
                expandRow(row, columnCount, orderUrlTemplate);
            } else if (!opening && isOpen) {
                collapseRow(row);
            }
        });

        expandAll.setAttribute('aria-expanded', opening ? 'true' : 'false');
        expandAll.setAttribute('aria-label', opening ? 'Collapse every row' : 'Expand every row');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('[data-reports-page]')) return;

    initRange();
    initPeriodChoices();
    initGroups();
    initDrilldown();
});
