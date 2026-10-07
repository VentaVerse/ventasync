import { initChannelListings } from './channel-listings';

function initManualAdd(section) {
    const searchUrl = section.getAttribute('data-search-url');
    const formId = section.getAttribute('data-form');
    const groupId = Number(section.getAttribute('data-group-id') || 0);
    const input = section.querySelector('[data-manual-search]');
    const go = section.querySelector('[data-manual-search-go]');
    const results = section.querySelector('[data-manual-results]');
    const add = section.querySelector('[data-manual-add]');
    if (!searchUrl || !formId || !input || !results) return;

    function message(text) {
        results.textContent = '';
        const empty = document.createElement('p');
        empty.className = 'fm-section__note';
        empty.textContent = text;
        results.appendChild(empty);
        if (add) add.disabled = true;
    }

    function refreshAddState() {
        if (!add) return;
        add.disabled = !results.querySelector('[data-manual-check]:checked');
    }

    function render(items) {
        results.textContent = '';

        const wrap = document.createElement('div');
        wrap.className = 'x-table-wrap';

        const table = document.createElement('table');
        table.className = 'x-table';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        [
            { text: '', cls: 'cc-col-check', sr: 'Select' },
            { text: 'ID', cls: 'cc-col-id' },
            { text: 'Product', cls: '' },
            { text: 'Price', cls: 'cc-col-price' },
        ].forEach(function (column) {
            const th = document.createElement('th');
            if (column.cls) th.className = column.cls;
            if (column.sr) {
                const sr = document.createElement('span');
                sr.className = 'x-sr';
                sr.textContent = column.sr;
                th.appendChild(sr);
            } else {
                th.textContent = column.text;
            }
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');

        items.forEach(function (item) {
            const tr = document.createElement('tr');

            const check = document.createElement('td');
            check.className = 'cc-col-check';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'cc-check';
            box.name = 'ids[]';
            box.value = String(item.product_id);
            box.setAttribute('form', formId);
            box.setAttribute('data-manual-check', '');
            box.setAttribute('aria-label', 'Add ' + (item.name || 'product ' + item.product_id));
            box.addEventListener('change', refreshAddState);
            check.appendChild(box);
            tr.appendChild(check);

            const ownedElsewhere = item.group_id && Number(item.group_id) !== groupId;
            if (ownedElsewhere) {
                box.disabled = true;
                box.setAttribute('aria-label', (item.name || 'Product ' + item.product_id) + " is in '" + item.group_name + "'");
                tr.className = 'cc-row--owned';
            }

            const id = document.createElement('td');
            id.className = 'cc-col-id';
            const idValue = document.createElement('span');
            idValue.className = 'x-num';
            idValue.textContent = String(item.product_id);
            id.appendChild(idValue);
            tr.appendChild(id);

            const product = document.createElement('td');
            const name = document.createElement('span');
            name.className = 'co-item__name';
            name.textContent = item.name || 'Unnamed product';
            product.appendChild(name);
            const meta = document.createElement('span');
            meta.className = 'co-item__meta';
            meta.textContent = item.sku || item.model || 'No SKU';
            product.appendChild(meta);
            if (ownedElsewhere) {
                const owner = document.createElement('span');
                owner.className = 'cc-owner';
                owner.textContent = "In: " + item.group_name;
                product.appendChild(owner);

                const move = document.createElement('button');
                move.type = 'button';
                move.className = 'cc-quiet cc-move';
                move.textContent = 'Move here';
                move.addEventListener('click', function () {
                    const form = document.getElementById(formId);
                    if (!form) return;
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'move_ids[]';
                    hidden.value = String(item.product_id);
                    form.appendChild(hidden);
                    box.disabled = false;
                    box.checked = true;
                    move.replaceWith(Object.assign(document.createElement('span'), {
                        className: 'cc-owner',
                        textContent: "moving here from '" + item.group_name + "'",
                    }));
                    refreshAddState();
                });
                product.appendChild(move);
            }
            tr.appendChild(product);

            const price = document.createElement('td');
            price.className = 'cc-col-price x-td-num';
            const priceValue = document.createElement('span');
            priceValue.className = 'x-num';
            priceValue.textContent = item.price ? Number(item.price).toFixed(2) : '0.00';
            price.appendChild(priceValue);
            tr.appendChild(price);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        wrap.appendChild(table);
        results.appendChild(wrap);
        refreshAddState();
    }

    function search() {
        const query = (input.value || '').trim();
        if (query.length < 2) {
            message('Type at least two characters.');
            return;
        }

        fetch(searchUrl + '?q=' + encodeURIComponent(query), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const items = Array.isArray(data.items) ? data.items : [];
                if (!items.length) {
                    message('No catalog product matches that.');
                    return;
                }
                render(items);
            })
            .catch(function () {
                message('The search did not come back. Try again.');
            });
    }

    if (go) go.addEventListener('click', search);

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); search(); }
    });

    if (add) {
        add.addEventListener('click', function (e) {
            if (!results.querySelector('[data-manual-check]:checked')) {
                e.preventDefault();
                if (typeof window.showFlashError === 'function') {
                    window.showFlashError('Tick at least one product first.');
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    [
        ['#ventacart-group-progress', 'VentaCart'],
        ['#opencart-group-progress', 'OpenCart'],
        ['#shopee-group-progress', 'Shopee'],
        ['#lazada-group-progress', 'Lazada'],
        ['#tiktok-group-progress', 'TikTok Shop'],
    ].forEach(function (channel) {
        initChannelListings({
            overlaySelector: channel[0],
            bulkField: 'ids[]',
            slowTitle: 'Talking to ' + channel[1],
            bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
            emptyMessage: 'Select at least one product first.',
        });
    });

    document.querySelectorAll('[data-group-manual-add]').forEach(initManualAdd);
});
