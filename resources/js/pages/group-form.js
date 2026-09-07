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
 *   2. Venta's product picker    a two-pane set builder over a catalog
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
        // catalog with thousands of categories from building thousands of
        // nodes on every keystroke.
        matches.slice(0, 50).forEach(function (option) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.name;
            // Two paths, deliberately. mousedown carries the MOUSE because the
            // input's blur fires before click and would tear the list down
            // first; that reasoning is real and is why click alone was used
            // here. But mousedown never fires for a keyboard, so binding only
            // mousedown left this picker unreachable without a mouse, exactly
            // the defect the category combobox below was fixed for. keydown
            // carries Enter and Space so a keyboard user can commit the option
            // their focus is already on.
            const choose = function (e) {
                e.preventDefault();
                addTag(option);
                search.value = '';
                hide();
            };

            button.addEventListener('mousedown', choose);

            button.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    choose(e);
                    search.focus();
                }
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
    const groupId = Number(root.getAttribute('data-group-id') || 0);
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

        /* One product, one group (cleanup point 3): a result another group
           already owns is shown, never hidden - read-only, its owner
           named, with Move here as the deliberate transfer. */
        const ownedElsewhere = side === 'left' && item.group_id && Number(item.group_id) !== groupId;

        const check = document.createElement('td');
        check.className = 'gf-col-check';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'cc-check';
        box.value = String(item.product_id);
        box.setAttribute('data-gf-check', side);
        box.setAttribute('aria-label', 'Select ' + (item.name || 'product ' + item.product_id));
        if (ownedElsewhere) {
            box.disabled = true;
            box.setAttribute('aria-label', (item.name || 'Product ' + item.product_id) + " is in '" + item.group_name + "'");
            tr.className = 'gf-row--owned';
        }
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
        if (ownedElsewhere) {
            const owner = document.createElement('span');
            owner.className = 'gf-owner';
            owner.textContent = "In: " + item.group_name;
            product.appendChild(owner);

            const move = document.createElement('button');
            move.type = 'button';
            move.className = 'gf-move';
            move.textContent = 'Move here';
            move.addEventListener('click', function () {
                item._move = true;
                right.push(item);
                left = left.filter(function (l) { return Number(l.product_id) !== Number(item.product_id); });
                renderLeft();
                renderRight();
            });
            product.appendChild(move);
        }
        if (side === 'right' && item._move) {
            const owner = document.createElement('span');
            owner.className = 'gf-owner';
            owner.textContent = "moves here from '" + item.group_name + "' on save";
            product.appendChild(owner);
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
            if (item._move) {
                const move = document.createElement('input');
                move.type = 'hidden';
                move.name = 'move_ids[]';
                move.value = String(item.product_id);
                inputs.appendChild(move);
            }
        });
    }

    function renderLeft() {
        const visible = left.filter(function (item) { return !inGroup(item.product_id); });
        if (leftCount) leftCount.textContent = String(visible.length);
        if (leftAll) { leftAll.checked = false; leftAll.indeterminate = false; }

        leftBody.textContent = '';
        if (!visible.length) {
            leftBody.appendChild(emptyRow(
                left.length ? 'Everything found is already in the group.' : 'Search the catalog to see products here.'
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

/* ==================================================================
   3. The marketplace category combobox (Shopee, TikTok)

   Round F Task 1. Both channels had their own inline copy of the same
   control: a text box that filters leaf categories and writes the
   chosen id into a hidden input beside it. They differ in exactly one
   thing, where the candidate list comes from:

     - Shopee asks the server on every keystroke, because it caches
       tens of thousands of leaves and shipping them all to the page is
       not an option;
     - TikTok filters an array the page already carries, because its
       leaf list is small and the controller already loaded it.

   That is `data-combo-mode`. Everything else is shared, including the
   part that matters: the hidden input's `change` event is what the
   brand loader and the attribute fetch listen for, so picking a
   category has to dispatch it exactly the way the originals did.

   SECURITY. Every option is createElement + textContent. A category
   named `<img src=x onerror=...>` is text here.
   ================================================================== */

let categoryComboSeq = 0;

function initCategoryCombo(root) {
    const mode = root.getAttribute('data-combo-mode') || 'local';
    const searchUrl = root.getAttribute('data-combo-search-url');
    const lookupUrl = root.getAttribute('data-combo-lookup-url');
    const input = root.querySelector('[data-combo-input]');
    const value = root.querySelector('[data-combo-value]');
    const results = root.querySelector('[data-combo-results]');
    const island = root.querySelector('[data-combo-options]');
    const extraParam = root.getAttribute('data-combo-extra-param');
    const extraSource = extraParam
        ? document.querySelector(root.getAttribute('data-combo-extra-source') || '')
        : null;
    const extraEmptyText = root.getAttribute('data-combo-extra-empty') || 'Pick a category first.';
    if (!input || !value || !results) return;

    // When what this combobox depends on changes, its own pick is void:
    // a brand id from one category means nothing in another.
    if (extraSource) {
        extraSource.addEventListener('change', function () {
            value.value = '';
            input.value = '';
            hide();
            warm();
        });
    }

    /* Warming (the owner, 2026-09-05): a first-time brand list is thousands of
       rows read from Shopee a page at a time, and the search box used to
       hang until the last page. Now the list is read the moment a category
       is picked, one request per page, with the count drawn as it grows;
       the box is locked until the list is in. A category already read to
       the end answers at once and nothing is shown. */
    const warmUrl = root.hasAttribute('data-combo-warm') ? searchUrl : null;
    const progress = root.querySelector('[data-combo-progress]');
    const progressText = root.querySelector('[data-combo-progress-text]');
    let warmRun = 0;

    function setProgress(state, text) {
        if (!progress) return;
        progress.hidden = state === 'idle';
        progress.dataset.state = state;
        if (progressText) progressText.textContent = text || '';
    }

    function warm() {
        if (!warmUrl || !extraParam || !extraSource || !extraSource.value) { setProgress('idle'); return; }
        const run = (warmRun += 1);
        const category = extraSource.value;
        const wasDisabled = input.disabled;
        let offset = 0;
        let shown = false;
        // The stall guard (live, 2026-09-07: "100 so far" never moved). A
        // page that grows the count by nothing three times in a row is the
        // end of the list, whatever the server says; the box unlocks and
        // the brands read so far are the list.
        let lastRead = -1;
        let stalls = 0;
        function step() {
            const url = new URL(warmUrl, window.location.origin);
            url.searchParams.set(extraParam, category);
            url.searchParams.set('warm', '1');
            url.searchParams.set('offset', String(offset));
            return fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (run !== warmRun) return; // the category changed under us
                    if (!data || !data.ok) {
                        setProgress('failed', (data && data.message) || 'Could not read the brands. Try again in a moment.');
                        input.disabled = wasDisabled;
                        return;
                    }
                    if (data.complete) {
                        input.disabled = wasDisabled;
                        if (shown) {
                            setProgress('done', data.read.toLocaleString() + ' brands ready.');
                            window.setTimeout(function () { if (run === warmRun) setProgress('idle'); }, 2500);
                        } else {
                            setProgress('idle');
                        }
                        return;
                    }
                    stalls = data.read === lastRead ? stalls + 1 : 0;
                    lastRead = data.read;
                    if (stalls >= 3) {
                        input.disabled = wasDisabled;
                        setProgress('done', data.read.toLocaleString() + ' brands ready (Shopee kept sending the same page).');
                        window.setTimeout(function () { if (run === warmRun) setProgress('idle'); }, 4000);
                        return;
                    }
                    shown = true;
                    input.disabled = true;
                    setProgress('running', 'Reading brands from Shopee… ' + data.read.toLocaleString() + ' so far');
                    offset = data.next_offset;
                    return step();
                })
                .catch(function () {
                    if (run !== warmRun) return;
                    setProgress('failed', 'Could not reach the server. Try again in a moment.');
                    input.disabled = wasDisabled;
                });
        }

        return step();
    }

    if (warmUrl) warm();

    // The popup is a real listbox and the field that opens it is a real
    // combobox: both roles need the pair of ids that ties them together,
    // and aria-expanded has to track the one thing that actually decides
    // whether the popup is showing (`results.hidden`), not a value set
    // once at load and forgotten.
    if (!results.id) results.id = 'gf-combo-results-' + (categoryComboSeq += 1);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', results.id);
    input.setAttribute('aria-expanded', 'false');

    let options = [];
    if (island) {
        try {
            const parsed = JSON.parse(island.textContent || '[]');
            if (Array.isArray(parsed)) options = parsed;
        } catch (e) {
            options = [];
        }
    }

    let timer = null;
    // Set immediately before a programmatic input.focus() that follows a
    // deliberate hide(), so the input's own 'focus' handler (below) does
    // not treat that refocus as a user tabbing back in and reopen the list
    // it was just told to close. Keyboard-only, found by actually pressing
    // Enter on a focused option: without this, commit() left the picked
    // value showing but silently reopened the results underneath it, and
    // Escape from an option closed the list for exactly one frame before
    // its own return-of-focus reopened it.
    let suppressFocusSearch = false;

    function hide() {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    // hide() plus returning focus to the field, without the field's own
    // focus handler re-running the search that would undo the hide.
    function hideAndFocusInput() {
        hide();
        suppressFocusSearch = true;
        input.focus();
    }

    function optionAt(index) {
        const all = results.querySelectorAll('.gf-combo__opt');
        return all[index] || null;
    }

    function focusOption(el) { if (el) el.focus(); }

    function commit(id, label) {
        value.value = String(id);
        input.value = label;
        // A remote list that is not cached locally (TikTok's brands) has
        // nowhere to look the name up again on the next page load, so the
        // picked words ride a sibling hidden input when the page asks.
        const labelStore = root.querySelector('[data-combo-label-store]');
        if (labelStore) labelStore.value = label;
        // Without this, focus was left on the option button, which the
        // hide() above turns into `display: none`; a hidden element cannot
        // hold focus, so the browser drops it to <body> and a keyboard
        // user loses their place in the form entirely.
        hideAndFocusInput();
        // The brand loader and the attribute fetch are bound to this
        // element's change event, exactly as they were before. Picking
        // from the list is not a user edit of the input, so nothing
        // fires it for us.
        value.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function label(item) {
        // Category labels carry the id (it is what API rejections quote);
        // a brand list is names an operator recognises, the id is noise.
        if (root.hasAttribute('data-combo-plain-label')) {
            return String(item.name || '');
        }
        return String(item.name || '') + ' (' + String(item.id) + ')';
    }

    function render(items) {
        results.textContent = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'gf-combo__empty';
            empty.textContent = 'No leaf category matches that.';
            results.appendChild(empty);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            return;
        }

        items.slice(0, 50).forEach(function (item, index) {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'gf-combo__opt';
            option.id = results.id + '-opt-' + index;
            option.setAttribute('role', 'option');

            const name = document.createElement('span');
            name.textContent = item.name || 'Unnamed category';
            option.appendChild(name);

            const id = document.createElement('span');
            id.className = 'gf-combo__id';
            id.textContent = String(item.id);
            option.appendChild(id);

            // A real button already receives `click` for a mouse click and
            // for a keyboard Enter/Space on whichever option currently has
            // focus, so one listener covers both input methods.
            option.addEventListener('click', function () {
                commit(item.id, label(item));
            });

            // Roving between options with the arrow keys, in addition to
            // the plain Tab stop each button already carries: Home/End
            // jump to the ends, Up from the first option (or Escape from
            // any option) hands focus back to the field.
            option.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    const next = optionAt(index + 1);
                    if (next) { e.preventDefault(); focusOption(next); }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prev = optionAt(index - 1);
                    if (prev) focusOption(prev); else input.focus();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    focusOption(optionAt(0));
                } else if (e.key === 'End') {
                    e.preventDefault();
                    focusOption(optionAt(results.querySelectorAll('.gf-combo__opt').length - 1));
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    hideAndFocusInput();
                }
            });

            results.appendChild(option);
        });

        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function localSearch(query) {
        const needle = query.toLowerCase();
        render(options.filter(function (item) {
            return String(item.name || '').toLowerCase().indexOf(needle) !== -1;
        }));
    }

    function remoteSearch(query) {
        if (!searchUrl) return;
        const url = new URL(searchUrl, window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('limit', '25');
        // A dependent combobox (the brand list belongs to a category)
        // carries the parameter name and where to read its value from.
        // No value yet means there is nothing to search IN: say so
        // instead of asking the server a question it must refuse.
        if (extraParam && extraSource) {
            if (!extraSource.value) {
                render([]);
                const empty = results.querySelector('.gf-combo__empty');
                if (empty) empty.textContent = extraEmptyText;
                return;
            }
            url.searchParams.set(extraParam, extraSource.value);
        }
        fetch(url.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                render((data.categories || data.brands || []).map(function (c) {
                    return { id: c.category_id !== undefined ? c.category_id : c.brand_id, name: c.name };
                }));
            })
            .catch(function () { hide(); });
    }

    function search(query) {
        if (mode === 'remote') remoteSearch(query);
        else if (query === '') hide();
        else localSearch(query);
    }

    input.addEventListener('input', function () {
        // Typing invalidates the pick. Without this an operator could
        // choose a category, edit the words, and save an id that no
        // longer matches what the box says.
        value.value = '';
        window.clearTimeout(timer);
        const query = input.value.trim();
        timer = window.setTimeout(function () { search(query); }, 200);
    });

    input.addEventListener('focus', function () {
        if (suppressFocusSearch) { suppressFocusSearch = false; return; }
        search(input.value.trim());
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { hide(); return; }
        // This control lives inside the group form. A bare Enter here
        // would submit a half-filled form.
        if (e.key === 'Enter') { e.preventDefault(); return; }
        // Down steps off the field and onto the first option, the same
        // move Tab makes, so a keyboard user is not required to know that
        // Tab happens to work here. Only meaningful once the popup is
        // actually open with something in it.
        if (e.key === 'ArrowDown' && !results.hidden) {
            const first = optionAt(0);
            if (first) { e.preventDefault(); focusOption(first); }
        }
    });

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) hide();
    });

    // Edit-page load: the hidden input carries an id but the visible box
    // is empty, because only the server knows what that id is called.
    if (value.value && lookupUrl) {
        const url = new URL(lookupUrl, window.location.origin);
        url.searchParams.set('category_id', value.value);
        fetch(url.toString(), { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.ok && data.category) {
                    input.value = label({ id: data.category.category_id, name: data.category.name });
                }
            })
            .catch(function () {});
    }

    // The Refresh button re-pulls the marketplace's category tree. It is
    // a manage-tier POST, so it is only in the markup for that tier.
    const refresh = root.querySelector('[data-combo-refresh]');
    const refreshUrl = root.getAttribute('data-combo-refresh-url');
    if (refresh && refreshUrl) {
        refresh.addEventListener('click', function () {
            const restore = refresh.textContent;
            refresh.disabled = true;
            refresh.textContent = 'Refreshing';

            fetch(refreshUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        if (typeof window.showFlashError === 'function') {
                            window.showFlashError((data && data.message) || 'Could not refresh the categories.');
                        }
                        return;
                    }
                    // TikTok returns the whole list and the page filters
                    // it locally, so the local copy has to be replaced.
                    // Shopee returns only a count and is re-searched.
                    if (Array.isArray(data.categories)) {
                        options = data.categories.map(function (c) {
                            return { id: c.id !== undefined ? c.id : c.category_id, name: c.name };
                        });
                    }
                    search(input.value.trim());
                    if (typeof window.showFlashSuccess === 'function') {
                        window.showFlashSuccess(data.count + ' categories refreshed.');
                    }
                })
                .catch(function () {
                    if (typeof window.showFlashError === 'function') {
                        window.showFlashError('Could not reach the marketplace to refresh the categories.');
                    }
                })
                .finally(function () {
                    refresh.disabled = false;
                    refresh.textContent = restore;
                });
        });
    }
}

/* ==================================================================
   5. Lazada: the brand typeahead

   A datalist, not a popover: Lazada's autocomplete returns names the
   operator recognises and the native control is the right size for
   it. The pair of hidden inputs behind it is the wire (`brand_id` and
   the `no_brand` flag), unchanged.
   ================================================================== */

function initBrandPicker(root) {
    const url = root.getAttribute('data-brand-url');
    const input = root.querySelector('[data-brand-input]');
    const list = root.querySelector('[data-brand-list]');
    const id = root.querySelector('[data-brand-id]');
    const none = root.querySelector('[data-brand-none-flag]');
    const picked = root.querySelector('[data-brand-picked]');
    const noneButton = root.querySelector('[data-brand-none]');
    if (!url || !input || !list || !id || !none) return;

    let lastQuery = '';

    function show(brandId) {
        if (picked) picked.textContent = brandId ? String(brandId) : 'None';
    }

    input.addEventListener('input', function () {
        const query = (input.value || '').trim();
        if (query === '' || query.toLowerCase() === 'no brand' || query === lastQuery) return;
        lastQuery = query;

        const endpoint = new URL(url, window.location.origin);
        endpoint.searchParams.set('q', query);
        endpoint.searchParams.set('limit', '20');

        fetch(endpoint.toString(), { headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : { items: [] }; })
            .then(function (data) {
                const rows = Array.isArray(data.items) ? data.items : [];
                list.textContent = '';
                rows.forEach(function (row) {
                    const option = document.createElement('option');
                    option.value = row.name;
                    option.dataset.id = String(row.brand_id);
                    list.appendChild(option);
                });
            })
            .catch(function () {});
    });

    input.addEventListener('change', function () {
        const typed = (input.value || '').trim();

        if (typed.toLowerCase() === 'no brand') {
            id.value = '';
            none.value = '1';
            show('');
            return;
        }

        const match = Array.from(list.options).find(function (option) {
            return (option.value || '').trim() === typed && option.dataset && option.dataset.id;
        });

        id.value = match ? match.dataset.id : '';
        none.value = '0';
        show(match ? match.dataset.id : '');
    });

    if (noneButton) {
        noneButton.addEventListener('click', function () {
            input.value = 'No Brand';
            id.value = '';
            none.value = '1';
            show('');
        });
    }

    show(id.value);
}

/* ==================================================================
   6. Category attributes, fetched when the category changes

   The endpoint answers with server-rendered Blade (the same
   _attributes partial the form renders inline), which is why this is
   the one place in these pages that assigns markup rather than
   building nodes. That markup comes from the application's own view
   layer with Blade's escaping applied, not from the response of a
   third party, and the endpoint is untouched by this redesign.
   ================================================================== */

function initAttributeFetch(root) {
    const url = root.getAttribute('data-attributes-url');
    const field = root.getAttribute('data-attributes-field');
    const source = document.querySelector(root.getAttribute('data-attributes-source') || '[data-combo-value]');
    const target = root.querySelector('[data-attributes-target]');
    if (!url || !field || !source || !target) return;

    /* The listing page (2026-09-06) keeps the section on screen with a
       sentence when no category is picked, carries a "Re-read the template"
       button beside the category, and names when the template was read.
       The group page has none of these and keeps hiding the section. */
    const emptyText = root.getAttribute('data-attributes-empty');
    const reread = root.hasAttribute('data-attributes-reread-button')
        ? document.querySelector(root.getAttribute('data-attributes-reread-button'))
        : null;
    const readAt = root.hasAttribute('data-attributes-read-at')
        ? document.querySelector(root.getAttribute('data-attributes-read-at'))
        : null;

    function say(text) {
        target.textContent = '';
        const note = document.createElement('p');
        note.className = 'fm-section__note';
        note.textContent = text;
        target.appendChild(note);
    }

    function load(categoryId, forceReread) {
        if (!categoryId) {
            if (emptyText) {
                say(emptyText);
            } else {
                root.hidden = true;
                target.textContent = '';
            }
            if (reread) reread.disabled = true;
            if (readAt) readAt.textContent = 'Attributes appear once a category is picked.';
            return;
        }

        root.hidden = false;
        if (reread) reread.disabled = true;
        say(forceReread ? 'Re-reading this category\'s attributes from Lazada.' : 'Loading the attributes for this category.');

        const body = new FormData();
        body.append(field, categoryId);
        if (forceReread) body.append('reread', '1');

        fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (reread) reread.disabled = false;
                if (data && data.ok && data.html) {
                    target.innerHTML = data.html;
                    bindAttributeRows();
                    if (readAt) readAt.textContent = data.fetched_at ? 'Attributes read from Lazada ' + data.fetched_at + '.' : '';
                    return;
                }
                say((data && data.message) || 'This category has no attributes to map.');
                if (readAt && data && data.ok) readAt.textContent = data.fetched_at ? 'Attributes read from Lazada ' + data.fetched_at + '.' : '';
            })
            .catch(function () {
                if (reread) reread.disabled = false;
                say('Could not reach the marketplace to load the attributes.');
            });
    }

    source.addEventListener('change', function () {
        load(source.value, false);
    });

    if (reread) {
        reread.addEventListener('click', function () {
            load(source.value, true);
        });
    }

    // A category saved before its template was read: read it now.
    if (root.hasAttribute('data-attributes-autoload') && source.value) {
        load(source.value, false);
    }
}

/* ==================================================================
   7. Lazada: one attribute value, set by hand or read off a product

   Two controls that are alternatives, plus a hidden input that is the
   only thing that submits. The `__map:<field>` prefix is the wire and
   is unchanged.

   Bound both at load and after an AJAX fetch injects new rows, so
   every row carries a guard against being bound twice.
   ================================================================== */

function erpFieldValues() {
    const island = document.querySelector('[data-erp-fields]');
    if (!island) return {};
    try {
        return JSON.parse(island.textContent || '{}') || {};
    } catch (e) {
        return {};
    }
}

function bindAttributeRows() {
    const values = erpFieldValues();

    document.querySelectorAll('[data-attr-row]').forEach(function (row) {
        if (row.dataset.attrBound === '1') return;

        const mode = row.querySelector('[data-attr-mode]');
        const manual = row.querySelector('[data-attr-manual]');
        const field = row.querySelector('[data-attr-field]');
        const hidden = row.querySelector('[data-attr-value]');
        if (!mode || !manual || !field || !hidden) return;

        row.dataset.attrBound = '1';

        const resolved = row.querySelector('[data-attr-resolved]');
        const resolvedText = row.querySelector('[data-attr-resolved-text]');

        // What actually gets shown and hidden. A <select> in this system is
        // wrapped in .x-select-wrap for its chevron, and hiding the select
        // while leaving the wrapper leaves a floating chevron behind, so the
        // markup marks the box to toggle and this falls back to the control
        // itself only if there is none.
        const manualBox = row.querySelector('[data-attr-manual-box]') || manual;
        const fieldBox = row.querySelector('[data-attr-field-box]') || field;

        function preview() {
            if (!resolved || !resolvedText) return;
            if (mode.value === 'product_field' && field.value) {
                let shown = values[field.value] || '(empty)';
                if (shown.length > 200) shown = shown.substring(0, 200) + '...';
                resolvedText.textContent = shown;
                resolved.hidden = false;
            } else {
                resolved.hidden = true;
            }
        }

        function sync() {
            hidden.value = mode.value === 'product_field'
                ? (field.value ? '__map:' + field.value : '')
                : manual.value;
            preview();
        }

        mode.addEventListener('change', function () {
            const mapped = mode.value === 'product_field';
            manualBox.hidden = mapped;
            fieldBox.hidden = !mapped;
            sync();
        });

        manual.addEventListener('input', sync);
        field.addEventListener('change', sync);
    });
}

/* ------------------------------------------------------------------
   Bind
   ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-group-filter-picker] .fm-field').forEach(initFilterPicker);
    document.querySelectorAll('[data-group-product-picker]').forEach(initProductPicker);
    document.querySelectorAll('[data-category-combo]').forEach(initCategoryCombo);
    document.querySelectorAll('[data-brand-picker]').forEach(initBrandPicker);
    document.querySelectorAll('[data-attributes-fetch]').forEach(initAttributeFetch);
    bindAttributeRows();
});
