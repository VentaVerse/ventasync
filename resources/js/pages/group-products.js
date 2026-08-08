/*
 * Product-group Products pages (Venta and OpenCart).
 *
 * Round C Task 3 rebuilt both on the shared cc- list anatomy and took them out
 * of the .x-legacy boundary. Their behaviour was an inline <script> in the
 * Blade; this project does not put behaviour in Blade.
 *
 * Almost none of it is new here. The bulk bar, the id injection and the
 * "talking to the marketplace" overlay are the same objects Shopee's and
 * Lazada's listings pages already have, so this file calls
 * ./channel-listings.js with these two channels' values rather than carrying a
 * third copy. The select-all box, the ticked count and the bar's visibility
 * are Alpine's job in the view, exactly as they are there.
 *
 * What is genuinely new is the "add a product by name" search, which those two
 * pages do not have: a GET to the group's own search-products endpoint, a
 * result list, and one POST carrying `ids[]` into a form that is already on
 * the page. Both channels use the same shape, so it is configured by data-
 * attribute rather than written twice.
 *
 * SECURITY. Every result row is createElement + textContent. Both originals
 * already built these rows that way, and this keeps it: the search endpoint
 * returns catalogue product names, which are operator-entered strings.
 *
 * The wire is unchanged: the same `ids[]` field, the same search endpoint and
 * two-character minimum, the same add-products POST.
 */

import { initChannelListings } from './channel-listings';

/* ------------------------------------------------------------------
   Add a product by name.
   ------------------------------------------------------------------ */

function initManualAdd(section) {
    const searchUrl = section.getAttribute('data-search-url');
    const formId = section.getAttribute('data-form');
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
            // The form attribute is what lets a control outside a <form>
            // submit with it. Same mechanism the pre-redesign page used.
            box.setAttribute('form', formId);
            box.setAttribute('data-manual-check', '');
            box.setAttribute('aria-label', 'Add ' + (item.name || 'product ' + item.product_id));
            box.addEventListener('change', refreshAddState);
            check.appendChild(box);
            tr.appendChild(check);

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
            tr.appendChild(product);

            const price = document.createElement('td');
            price.className = 'cc-col-price x-td-num';
            const priceValue = document.createElement('span');
            priceValue.className = 'x-num';
            // The endpoint returns a bare decimal. The currency symbol is
            // never hardcoded anywhere in this project, and there is nothing
            // here that can resolve it, so the figure is shown unlabelled
            // rather than with a guessed symbol in front of it.
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
                    message('No catalogue product matches that.');
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
        // This control sits beside forms, so a bare Enter must not submit one.
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

/* ------------------------------------------------------------------
   Bind
   ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function () {
    // Both channels post `ids[]` and key on the catalogue product id, so the
    // only per-channel values are the overlay element and the wording.
    initChannelListings({
        overlaySelector: '#venta-group-progress',
        bulkField: 'ids[]',
        slowTitle: 'Talking to Venta',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });

    initChannelListings({
        overlaySelector: '#opencart-group-progress',
        bulkField: 'ids[]',
        slowTitle: 'Talking to OpenCart',
        bulkTitle: (count) => 'Working on ' + count + (count === 1 ? ' product' : ' products'),
        emptyMessage: 'Select at least one product first.',
    });

    document.querySelectorAll('[data-group-manual-add]').forEach(initManualAdd);
});
