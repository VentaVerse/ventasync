/**
 * Reports pages (`rp-` anatomy, Round D Task 3).
 *
 * Four behaviours, all inert on a page that does not carry the markup,
 * following the same guard convention as confirm-modal.js and
 * list-filters.js:
 *
 *   1. The date range presets, which write into the two date inputs.
 *   2. The "More filters" disclosure.
 *   3. The month/period group toggles on the orders and fees reports.
 *   4. The product drill-down on the products report.
 *
 * Nothing here uses innerHTML. Every node is built with createElement and
 * every string reaches the DOM through textContent, because a product
 * name, a customer name and a marketplace status are all values somebody
 * else typed.
 */

/* ------------------------------------------------------------------
   1. Date range presets
   ------------------------------------------------------------------ */

function pad(n) {
    return n < 10 ? '0' + n : '' + n;
}

function fmt(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function lastDay(year, month) {
    return new Date(year, month + 1, 0).getDate();
}

/**
 * Resolve a preset key to a { from, to } pair of Y-m-d strings.
 * Behaviour is unchanged from the three copies of this function that used
 * to sit inline in orders.blade.php, products.blade.php and
 * payouts.blade.php, including the Monday-first week.
 */
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
    const select = document.querySelector('[data-range-preset]');
    const from = document.querySelector('[data-range-from]');
    const to = document.querySelector('[data-range-to]');
    if (!select || !from || !to) return;

    // If the dates already on the page are exactly one of the presets, say
    // so rather than leaving the select reading "Custom range" over a range
    // that is anything but.
    for (let i = 0; i < PRESETS.length; i += 1) {
        const range = presetRange(PRESETS[i]);
        if (range && range.from === from.value && range.to === to.value) {
            select.value = PRESETS[i];
            break;
        }
    }

    select.addEventListener('change', function () {
        const range = presetRange(select.value);
        if (range) {
            from.value = range.from;
            to.value = range.to;
        }
    });

    // Typing a date by hand is a custom range by definition.
    from.addEventListener('change', function () { select.value = 'custom'; });
    to.addEventListener('change', function () { select.value = 'custom'; });
}

/* ------------------------------------------------------------------
   2. "More filters" disclosure
   ------------------------------------------------------------------ */

function initMoreFilters() {
    const toggle = document.querySelector('[data-more-toggle]');
    if (!toggle) return;

    const panel = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!panel) return;

    // Remembered per page, keyed on the path, so the operator who works
    // with the narrowing fields open does not reopen them every visit.
    const key = 'xenon.reports.more:' + window.location.pathname;

    try {
        if (localStorage.getItem(key) === '1' && panel.hasAttribute('hidden')) {
            panel.removeAttribute('hidden');
            toggle.setAttribute('aria-expanded', 'true');
        }
    } catch (e) {
        /* Private browsing denies localStorage; the panel still works. */
    }

    toggle.addEventListener('click', function () {
        const opening = panel.hasAttribute('hidden');
        if (opening) {
            panel.removeAttribute('hidden');
        } else {
            panel.setAttribute('hidden', '');
        }
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        try {
            localStorage.setItem(key, opening ? '1' : '0');
        } catch (e) {
            /* as above */
        }
    });
}

/* ------------------------------------------------------------------
   3. Month / period groups
   ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------
   4. Product drill-down
   ------------------------------------------------------------------ */

/**
 * Reproduce App\Support\Money::base() exactly: sign, then the symbol from
 * the `currencies` table (handed over in the JSON payload, never
 * hardcoded here), then the grouped two-decimal number. PHP's
 * number_format uses comma grouping and a dot decimal, which is what
 * en-US produces.
 *
 * The code this replaces called toLocaleString('en-PH') with no symbol at
 * all, so every figure in the drill-down panel was an unlabelled number
 * on a page whose whole subject is money.
 */
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

    // Who bought it, first: that is the question this panel was added for.
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
    initMoreFilters();
    initGroups();
    initDrilldown();
});
