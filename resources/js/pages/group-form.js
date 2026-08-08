/*
 * Product-group form pages: the two pickers those forms carry.
 *
 * Round C Task 3 rebuilt both channels' product-group create/edit forms on the
 * fm- anatomy and took them out of the .x-legacy boundary. Their behaviour was
 * an inline <script> in the Blade; this project does not put behaviour in
 * Blade, so it lives here.
 *
 * Two different controls, because the two channels genuinely group products
 * differently and neither is a variant of the other:
 *
 *   1. OpenCart's filter picker  two tag fields (categories, manufacturers)
 *                                over a list the server already shipped with
 *                                the page. What submits is one hidden input
 *                                per tag, named `catalog_category_ids[]` /
 *                                `manufacturer_ids[]`.
 *
 *   2. Venta's product picker    a two-pane set builder over a catalogue
 *                                search endpoint. What submits is one hidden
 *                                `product_ids[]` per row on the right.
 *
 * Both read their data from a <script type="application/json"> island the
 * Blade renders with JSON_HEX_TAG, rather than having the payload interpolated
 * into a script literal. Every node is createElement + textContent; a product
 * or category named `<img src=x onerror=...>` is text here, not markup.
 *
 * The wire is unchanged for both: the same field names, the same search
 * endpoint, the same query parameters.
 */

/* ==================================================================
   1. OpenCart: the category / manufacturer tag picker
   ================================================================== */

function initFilterPicker(field) {
    const tags = field.querySelector('[data-picker-tags]');
    const search = field.querySelector('[data-picker-search]');
    const list = field.querySelector('[data-picker-list]');
    const island = field.querySelector('[data-picker-options]');
    if (!tags || !search || !list || !island) return;

    const fieldName = tags.getAttribute('data-picker-tags');
    const noun = tags.getAttribute('data-picker-noun') || 'item';

    let options = [];
    try {
        const parsed = JSON.parse(island.textContent || '[]');
        if (Array.isArray(parsed)) options = parsed;
    } catch (e) {
        options = [];
    }

    function chosenIds() {
        return Array.from(tags.querySelectorAll('.fm-tag')).map(function (tag) {
            return String(tag.getAttribute('data-id'));
        });
    }

    function addTag(option) {
        const tag = document.createElement('span');
        tag.className = 'fm-tag';
        tag.setAttribute('data-id', String(option.id));

        const label = document.createElement('span');
        label.textContent = option.name;
        tag.appendChild(label);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = fieldName;
        hidden.value = String(option.id);
        tag.appendChild(hidden);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'fm-tag__x';
        remove.setAttribute('aria-label', 'Remove ' + option.name);
        remove.textContent = '×';
        remove.addEventListener('click', function () { tag.remove(); });
        tag.appendChild(remove);

        tags.appendChild(tag);
    }

    function hide() { list.style.display = 'none'; }

    function render(query) {
        const needle = query.toLowerCase();
        const taken = chosenIds();

        const matches = options.filter(function (option) {
            return taken.indexOf(String(option.id)) === -1
                && String(option.name).toLowerCase().indexOf(needle) !== -1;
        });

        list.textContent = '';

        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'fm-ta__empty';
            empty.textContent = 'No ' + noun + ' matches that.';
            list.appendChild(empty);
            list.style.display = 'block';
            return;
        }

        // Fifty is what the pre-redesign picker showed, and it is what keeps a
        // catalogue with thousands of categories from building thousands of
        // nodes on every keystroke.
        matches.slice(0, 50).forEach(function (option) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.name;
            button.addEventListener('mousedown', function (e) {
                // mousedown, not click: the input's blur fires first otherwise
                // and the list is gone before the click lands.
                e.preventDefault();
                addTag(option);
                search.value = '';
                hide();
            });
            list.appendChild(button);
        });

        list.style.display = 'block';
    }

    search.addEventListener('input', function () {
        const query = search.value.trim();
        if (!query) { hide(); return; }
        render(query);
    });

    search.addEventListener('focus', function () {
        const query = search.value.trim();
        if (query) render(query);
    });

    search.addEventListener('blur', function () {
        window.setTimeout(hide, 150);
    });

    search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hide();
    });

    // Tags the server rendered carry their own remove button with no handler
    // (this project does not use inline onclick), so they are bound here.
    tags.querySelectorAll('.fm-tag__x').forEach(function (button) {
        button.addEventListener('click', function () {
            const tag = button.closest('.fm-tag');
            if (tag) tag.remove();
        });
    });
}

/* ==================================================================
   2. Venta: the two-pane product picker
   ================================================================== */

function initProductPicker(root) {
    const searchUrl = root.getAttribute('data-search-url');
    const leftBody = root.querySelector('[data-gf-left]');
    const rightBody = root.querySelector('[data-gf-right]');
    const leftCount = root.querySelector('[data-gf-left-count]');
    const rightCount = root.querySelector('[data-gf-right-count]');
    const inputs = root.querySelector('[data-gf-inputs]');
    if (!searchUrl || !leftBody || !rightBody || !inputs) return;

    const qInput = root.querySelector('[data-gf-q]');
    const categorySelect = root.querySelector('[data-gf-category]');
    const manufacturerSelect = root.querySelector('[data-gf-manufacturer]');
    const searchButton = root.querySelector('[data-gf-search]');
    const addButton = root.querySelector('[data-gf-add]');
    const removeButton = root.querySelector('[data-gf-remove]');
    const leftAll = root.querySelector('[data-gf-left-all]');
    const rightAll = root.querySelector('[data-gf-right-all]');
    const island = root.querySelector('[data-gf-initial]');

    let left = [];
    let right = [];
    try {
        const parsed = JSON.parse((island && island.textContent) || '[]');
        if (Array.isArray(parsed)) right = parsed;
    } catch (e) {
        right = [];
    }

    function inGroup(id) {
        return right.some(function (item) { return Number(item.product_id) === Number(id); });
    }

    function emptyRow(message) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 4;
        td.className = 'gf-empty';
        td.textContent = message;
        tr.appendChild(td);
        return tr;
    }

    function row(item, side) {
        const tr = document.createElement('tr');

        const check = document.createElement('td');
        check.className = 'gf-col-check';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'cc-check';
        box.value = String(item.product_id);
        box.setAttribute('data-gf-check', side);
        box.setAttribute('aria-label', 'Select ' + (item.name || 'product ' + item.product_id));
        check.appendChild(box);
        tr.appendChild(check);

        const id = document.createElement('td');
        id.className = 'gf-col-id';
        id.textContent = String(item.product_id);
        tr.appendChild(id);

        const product = document.createElement('td');
        const name = document.createElement('span');
        name.className = 'gf-name';
        name.textContent = item.name || 'Unnamed product';
        product.appendChild(name);
        if (item.sku) {
            const sku = document.createElement('span');
            sku.className = 'gf-sku';
            sku.textContent = item.sku;
            product.appendChild(sku);
        }
        tr.appendChild(product);

        const qty = document.createElement('td');
        qty.className = 'gf-col-qty';
        qty.textContent = String(item.quantity);
        tr.appendChild(qty);

        return tr;
    }

    function syncInputs() {
        inputs.textContent = '';
        right.forEach(function (item) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'product_ids[]';
            hidden.value = String(item.product_id);
            inputs.appendChild(hidden);
        });
    }

    function renderLeft() {
        const visible = left.filter(function (item) { return !inGroup(item.product_id); });
        if (leftCount) leftCount.textContent = String(visible.length);
        if (leftAll) { leftAll.checked = false; leftAll.indeterminate = false; }

        leftBody.textContent = '';
        if (!visible.length) {
            leftBody.appendChild(emptyRow(
                left.length ? 'Everything found is already in the group.' : 'Search the catalogue to see products here.'
            ));
            return;
        }
        visible.forEach(function (item) { leftBody.appendChild(row(item, 'left')); });
    }

    function renderRight() {
        if (rightCount) rightCount.textContent = String(right.length);
        if (rightAll) { rightAll.checked = false; rightAll.indeterminate = false; }
        syncInputs();

        rightBody.textContent = '';
        if (!right.length) {
            rightBody.appendChild(emptyRow('Nothing in the group yet.'));
            return;
        }
        right.forEach(function (item) { rightBody.appendChild(row(item, 'right')); });
    }

    function ticked(side) {
        const body = side === 'left' ? leftBody : rightBody;
        return Array.from(body.querySelectorAll('[data-gf-check]'))
            .filter(function (box) { return box.checked; })
            .map(function (box) { return Number(box.value); });
    }

    function search() {
        const params = new URLSearchParams();
        const q = qInput ? qInput.value.trim() : '';
        if (q) params.set('q', q);
        if (categorySelect && categorySelect.value) params.set('category_id', categorySelect.value);
        if (manufacturerSelect && manufacturerSelect.value) params.set('manufacturer_id', manufacturerSelect.value);

        const restoreLabel = searchButton ? searchButton.textContent : '';
        if (searchButton) { searchButton.disabled = true; searchButton.textContent = 'Searching'; }

        fetch(searchUrl + '?' + params.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                left = (Array.isArray(data.items) ? data.items : []).map(function (item) {
                    return {
                        product_id: parseInt(item.product_id, 10),
                        name: String(item.name || ''),
                        sku: String(item.sku || item.model || ''),
                        quantity: parseInt(item.quantity, 10) || 0,
                    };
                });
                renderLeft();
            })
            .catch(function () {
                left = [];
                leftBody.textContent = '';
                leftBody.appendChild(emptyRow('The search did not come back. Try again.'));
                if (leftCount) leftCount.textContent = '0';
            })
            .finally(function () {
                if (searchButton) { searchButton.disabled = false; searchButton.textContent = restoreLabel; }
            });
    }

    if (searchButton) searchButton.addEventListener('click', search);
    if (qInput) {
        qInput.addEventListener('keydown', function (e) {
            // The picker sits inside the group form, so Enter here would submit
            // the whole form and save a half-built group.
            if (e.key === 'Enter') { e.preventDefault(); search(); }
        });
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            ticked('left').forEach(function (id) {
                if (inGroup(id)) return;
                const item = left.find(function (candidate) { return Number(candidate.product_id) === id; });
                if (item) right.push(item);
            });
            renderLeft();
            renderRight();
        });
    }

    if (removeButton) {
        removeButton.addEventListener('click', function () {
            const going = ticked('right');
            if (!going.length) return;
            right = right.filter(function (item) { return going.indexOf(Number(item.product_id)) === -1; });
            renderLeft();
            renderRight();
        });
    }

    if (leftAll) {
        leftAll.addEventListener('change', function () {
            leftBody.querySelectorAll('[data-gf-check]').forEach(function (box) { box.checked = leftAll.checked; });
        });
    }
    if (rightAll) {
        rightAll.addEventListener('change', function () {
            rightBody.querySelectorAll('[data-gf-check]').forEach(function (box) { box.checked = rightAll.checked; });
        });
    }

    renderLeft();
    renderRight();
}

/* ------------------------------------------------------------------
   Bind
   ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-group-filter-picker] .fm-field').forEach(initFilterPicker);
    document.querySelectorAll('[data-group-product-picker]').forEach(initProductPicker);
});
