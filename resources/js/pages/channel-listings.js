/*
 * Channel Listings: the shared behaviour every marketplace listings page needs.
 *
 * Round C Task 1 ported Shopee's inline listings script into
 * pages/shopee-listings.js. Task 2 brought Lazada onto the same page anatomy,
 * and Lazada's page needs the same four things: a work-in-progress overlay, a
 * bulk bar that injects the ticked ids into whichever bulk form was submitted,
 * a "talking to the marketplace" overlay on single-row actions, and a
 * catalog typeahead for the unmatched-item queue.
 *
 * The two channels differ in exactly three values, so those are configuration
 * rather than a second copy of the module:
 *
 *   - the overlay element's id (each page has its own),
 *   - the name of the array field the bulk forms post (Shopee posts
 *     `product_ids[]` and keys on catalog product ids; Lazada posts
 *     `listing_ids[]` and keys on its own listing rows),
 *   - the wording of the overlay and the empty-selection message.
 *
 * Everything else is identical, including the parts that matter most:
 *
 *   1. The typeahead result list is built with createElement and textContent.
 *      Both channels' original inline scripts did
 *      `resultsDiv.innerHTML = items.map(...)` with a product name taken
 *      straight off the search endpoint's JSON, which is raw innerHTML with
 *      database-controlled strings. A product named `<img src=x onerror=...>`
 *      executed.
 *   2. Select-all, the selected count and the bulk bar's visibility are
 *      Alpine's job (x-model / x-show in the view). This module only does the
 *      part Alpine cannot: injecting the ticked ids into the submitted form.
 *      It reads the checkboxes from the DOM rather than Alpine's state so the
 *      two can never disagree.
 *   3. Every URL comes off a data- attribute rather than being Blade-
 *      interpolated into a script literal.
 *
 * The wire is unchanged for both channels: the same hidden id inputs, the same
 * `product_id` on the link form, the same GET to search-catalog?q=, the same
 * 300ms debounce and the same two-character minimum.
 */

/* ------------------------------------------------------------------
   Work-in-progress overlay. Rides components/modals.css's .modal-backdrop
   show/hide mechanics: one `.active` class, the same mechanism
   partials/confirm-modal.blade.php and the orders pages already use.
   ------------------------------------------------------------------ */

function makeProgress(overlaySelector) {
    return function showProgress(title) {
        const overlay = document.querySelector(overlaySelector);
        if (!overlay) return;
        const titleEl = overlay.querySelector('[data-progress-title]');
        if (titleEl && title) titleEl.textContent = title;
        overlay.classList.add('active');
    };
}

/* ------------------------------------------------------------------
   Bulk actions.
   ------------------------------------------------------------------ */

/**
 * The ids the ticked rows contribute for one bulk form.
 *
 * By default that is each checkbox's own value, which is what every channel
 * whose bulk endpoints all take the same kind of id needs.
 *
 * `attr` names a data attribute to read instead, for a page whose endpoints
 * do not all speak the same id. Pedallion is the one such page: four of its
 * six bulk endpoints take `link_ids[]` (rows that are actually linked to
 * Pedallion) and two take `product_ids[]` (any catalog product). A row with
 * no link contributes nothing to the first group, which is why blanks are
 * dropped here rather than posted as empty strings for the server to reject.
 */
function tickedIds(attr) {
    return Array.from(document.querySelectorAll('[data-row-check]'))
        .filter((box) => box.checked)
        .map((box) => (attr ? (box.getAttribute(attr) || '') : box.value))
        .filter((id) => String(id) !== '');
}

/**
 * The names behind the ticked boxes, in the order they appear on screen.
 *
 * A row's checkbox and its product name live in the same <tr>, so the name is
 * one closest() away. Returns [] on a page whose rows are shaped differently,
 * and the caller degrades to a bare count rather than to a wrong list.
 */
function tickedNames() {
    return Array.from(document.querySelectorAll('[data-row-check]'))
        .filter((box) => box.checked)
        .map((box) => {
            const row = box.closest('tr');
            const name = row && row.querySelector('.co-item__name, .x-row-link');
            return name ? name.textContent.trim() : '';
        })
        .filter((name) => name !== '');
}

/**
 * What the operator is actually about to do, in a dialog that used to refuse
 * to say.
 *
 * "Delete the selected products from Shopee?" read identically for one product
 * and for forty-seven, and named none of them. The count is the fact that
 * changes the decision, and on an irreversible public deletion the names are
 * the difference between confirming and checking.
 *
 * Capped at six with an honest remainder: a dialog listing forty-seven names
 * is a wall the operator scrolls past, which is the same as not reading it.
 */
const CONFIRM_NAME_LIMIT = 6;

function buildBulkConfirm(message, ids) {
    const names = tickedNames();
    const noun = ids.length === 1 ? 'product' : 'products';
    let out = message + '\n\n' + ids.length + ' ' + noun;

    // Only when the names line up with the selection. A partial list beside a
    // full count would misrepresent the scope of the action.
    if (names.length === ids.length) {
        out += ':\n' + names.slice(0, CONFIRM_NAME_LIMIT).join('\n');
        if (names.length > CONFIRM_NAME_LIMIT) {
            out += '\nand ' + (names.length - CONFIRM_NAME_LIMIT) + ' more';
        }
    }

    return out;
}

function injectIds(form, ids, fieldName) {
    form.querySelectorAll('input[name="' + fieldName + '"]').forEach((n) => n.remove());
    ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = fieldName;
        input.value = id;
        form.appendChild(input);
    });
}

function initBulkForms(config, showProgress) {
    document.querySelectorAll('form[data-bulk-form]').forEach((form) => {
        form.addEventListener('submit', function (e) {
            // Second pass, after a confirmation was accepted: let it through.
            if (form.dataset.bulkSubmitting === '1') {
                form.dataset.bulkSubmitting = '';
                return;
            }

            e.preventDefault();

            // Both default to the page's own single answer, so a channel
            // whose endpoints all take the same kind of id declares nothing
            // per form and nothing about its markup changes.
            const field = form.getAttribute('data-bulk-field') || config.bulkField;
            const attr = form.getAttribute('data-bulk-attr') || '';

            let ids = tickedIds(attr);
            // A verb form acts only on the rows its verb applies to; the
            // rest of the selection is named as skipped on the bar.
            const verbCodes = form.getAttribute('data-verb-codes');
            if (verbCodes) {
                const codes = verbCodes.split(',');
                const allowed = Array.from(document.querySelectorAll('[data-row-check]'))
                    .filter((box) => box.checked && codes.includes(box.getAttribute('data-live') || ''))
                    .map((box) => (attr ? (box.getAttribute(attr) || '') : box.value));
                ids = ids.filter((id) => allowed.includes(id));
            }
            if (!ids.length) {
                // With no attribute this is unreachable in normal use (the bar
                // is x-show'd on a non-empty selection) and stays as the guard
                // both originals had. With one it is genuinely reachable: the
                // operator ticked rows that carry no link, and the message
                // says which rows this action needs rather than letting the
                // POST round-trip to be refused.
                const message = form.getAttribute('data-bulk-empty') || config.emptyMessage;
                if (typeof window.showFlashError === 'function') {
                    window.showFlashError(message);
                }
                return;
            }

            const submit = function () {
                injectIds(form, ids, field);
                showProgress(config.bulkTitle(ids.length));
                const button = form.querySelector('button[type="submit"]');
                if (button) button.disabled = true;
                // Native submit(), NOT requestSubmit(). For the no-confirm
                // actions this runs synchronously inside the form's own submit
                // dispatch, and a re-entrant requestSubmit() during an in-flight
                // submit is silently dropped by the browser — no event, no
                // error, the overlay just span forever and nothing posted.
                // submit() navigates unconditionally and fires no submit event,
                // so the re-entrancy guard above is no longer needed.
                form.submit();
            };

            const message = form.getAttribute('data-bulk-confirm');
            if (message && typeof window.confirmModal === 'function') {
                window.confirmModal(buildBulkConfirm(message, ids), document.activeElement).then(function (ok) {
                    if (ok) submit();
                });
                return;
            }

            submit();
        });
    });
}

/* ------------------------------------------------------------------
   Single-row marketplace actions. The overlay only appears once the
   submit has actually survived: a form carrying data-confirm is
   preventDefault()ed by resources/js/confirm-modal.js's capture-phase
   listener until the operator accepts, and showing a "talking to the
   marketplace" overlay behind a dialog nobody has answered yet is a lie.
   ------------------------------------------------------------------ */

function initSlowActions(config, showProgress) {
    document.querySelectorAll('form[data-slow-action]').forEach((form) => {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            showProgress(config.slowTitle);
        });
    });
}

/* ------------------------------------------------------------------
   Unmatched items: catalog typeahead.
   ------------------------------------------------------------------ */

function buildResultButton(item, onPick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('role', 'option');

    const row = document.createElement('span');
    row.className = 'cc-ta__row';

    if (item.image) {
        const thumb = document.createElement('span');
        thumb.className = 'cc-thumb cc-thumb--sm';
        const img = document.createElement('img');
        // A src is an attribute, not markup: assigning it cannot introduce
        // an element. The endpoint returns asset() URLs off the catalog's
        // own image column.
        img.src = item.image;
        img.alt = '';
        img.loading = 'lazy';
        thumb.appendChild(img);
        row.appendChild(thumb);
    }

    const body = document.createElement('span');
    body.className = 'cc-ta__body';

    const name = document.createElement('span');
    name.className = 'cc-ta__name';
    name.textContent = item.name || 'Unnamed product';
    body.appendChild(name);

    const meta = document.createElement('span');
    meta.className = 'cc-ta__meta';
    const identifiers = [];
    if (item.model) identifiers.push(String(item.model));
    if (item.sku && item.sku !== item.model) identifiers.push(String(item.sku));
    identifiers.push('ID ' + item.product_id);
    meta.textContent = identifiers.join(' / ');
    body.appendChild(meta);

    // Variation lines. "Variation" is the user-facing word across this app
    // and across every marketplace it talks to; the database columns behind
    // it are still option_*.
    if (item.options && item.options.length) {
        item.options.forEach((option) => {
            const line = document.createElement('span');
            line.className = 'cc-ta__var';
            const label = option.name ? option.name + ' / ' : '';
            line.textContent = label + option.sku + ' / ' + option.qty + ' in stock';
            body.appendChild(line);
        });
    }

    row.appendChild(body);
    button.appendChild(row);

    button.addEventListener('click', function () {
        onPick(item);
    });

    return button;
}

/**
 * Bind a single-select catalog picker.
 *
 * Round F Task 1 note: the root does not have to be a <form>. Shopee's
 * "add a product to Shopee" page and Lazada's "create a listing" page
 * carry the identical control (search the catalog, pick one product,
 * write its id into a hidden input) and one of them has no form at all,
 * so this reads only getAttribute / querySelector / contains, all of
 * which any element answers. The unmatched-item linker on the two
 * listings pages is the same control on a <form>, unchanged.
 */
export function initCatalogPicker(form) {
    const searchUrl = form.getAttribute('data-search-url');
    const input = form.querySelector('[data-unmatched-search]');
    const list = form.querySelector('[data-unmatched-results]');
    const hidden = form.querySelector('[data-unmatched-id]');
    const submit = form.querySelector('[data-unmatched-submit]');
    if (!searchUrl || !input || !list || !hidden) return;

    let timer = null;

    function hide() {
        list.style.display = 'none';
    }

    function clear() {
        list.textContent = '';
        hide();
    }

    function show() {
        list.style.display = 'block';
    }

    function message(text) {
        list.textContent = '';
        const empty = document.createElement('div');
        empty.className = 'fm-ta__empty';
        empty.textContent = text;
        list.appendChild(empty);
        show();
    }

    function pick(item) {
        hidden.value = String(item.product_id);
        input.value = (item.name || 'Unnamed product') + ' (ID ' + item.product_id + ')';
        clear();
        if (submit) submit.disabled = false;
        // Anything that has to react to the choice (the Continue button on
        // Shopee's add page) listens here rather than reaching into this
        // module. Same event the group form's category combobox fires.
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    input.addEventListener('input', function () {
        // Typing invalidates a previous pick: the hidden id goes back to
        // empty and Link is disabled again until something is chosen from
        // the list. Without this an operator could pick a product, edit the
        // text, and link a product whose name is no longer in the box.
        hidden.value = '';
        if (submit) submit.disabled = true;
        hidden.dispatchEvent(new Event('change', { bubbles: true }));

        const query = input.value.trim();
        if (query.length < 2) {
            clear();
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            fetch(searchUrl + '?q=' + encodeURIComponent(query), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.json())
                .then((items) => {
                    if (!Array.isArray(items) || !items.length) {
                        message('No catalog product matches that.');
                        return;
                    }
                    list.textContent = '';
                    items.forEach((item) => list.appendChild(buildResultButton(item, pick)));
                    show();
                })
                .catch(() => {
                    message('Could not reach the catalog. Try again.');
                });
        }, 300);
    });

    // Closing on an outside click is scoped to this form, so two linkers on
    // the same page do not close each other's lists on every click.
    document.addEventListener('click', function (e) {
        if (!form.contains(e.target)) hide();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') clear();
    });
}

/**
 * Bind the listings behaviour for one channel.
 *
 * @param {object} config
 * @param {string} config.overlaySelector  The page's progress overlay element.
 * @param {string} config.bulkField        Array field name the bulk forms post.
 * @param {string} config.slowTitle        Overlay title for a single-row action.
 * @param {function(number):string} config.bulkTitle  Overlay title for a bulk run.
 * @param {string} config.emptyMessage     Shown when a bulk form has no selection.
 */
/* The selection bar offers only the verbs the ticked rows can take, and
   says what it will skip. A verb form carries data-bulk-verb, the codes
   it applies to in data-verb-codes, and its label with :n in
   data-verb-label. */
function updateBulkVerbs() {
    const forms = Array.from(document.querySelectorAll('form[data-bulk-verb]'));
    if (!forms.length) return;
    const picked = Array.from(document.querySelectorAll('[data-row-check]')).filter((b) => b.checked);
    let covered = 0;
    forms.forEach((form) => {
        const codes = (form.getAttribute('data-verb-codes') || '').split(',');
        const n = picked.filter((b) => codes.includes(b.getAttribute('data-live') || '')).length;
        covered += n;
        const button = form.querySelector('button[type="submit"]');
        if (button) button.textContent = (form.getAttribute('data-verb-label') || ':n').replace(':n', n);
        form.hidden = n === 0;
    });
    const note = document.querySelector('[data-bulk-skipped]');
    if (note) {
        const skipped = picked.length - covered;
        note.textContent = picked.length > 0 && skipped > 0 ? skipped + ' cannot be changed here' : '';
    }
}

function initBulkVerbs() {
    if (!document.querySelector('form[data-bulk-verb]')) return;
    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[type="checkbox"]')) {
            // Alpine writes the row boxes after select-all; wait a tick.
            setTimeout(updateBulkVerbs, 0);
        }
    });
    updateBulkVerbs();
}

export function initChannelListings(config) {
    // One presence check for the whole module: the overlay only exists on
    // this channel's listings page. Everything below is scoped to it or to
    // its own attribute, so the module is inert everywhere else.
    if (!document.querySelector(config.overlaySelector)) return;

    const showProgress = makeProgress(config.overlaySelector);

    initBulkForms(config, showProgress);
    initSlowActions(config, showProgress);
    document.querySelectorAll('form[data-unmatched-link]').forEach(initCatalogPicker);
    initBulkVerbs();
}
