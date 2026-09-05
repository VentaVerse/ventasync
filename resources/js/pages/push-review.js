/**
 * The push-review modal (cleanup 7+9, approved from the 2026-09-03 draft).
 *
 * SINGLE: a form marked data-push-review is intercepted; the endpoint's
 * JSON fills the modal - what goes up, price arithmetic included, with
 * title/description as the two editable fields - and Push submits the
 * original form with those two riding along. No JS, no interception: the
 * form still pushes with a native submit.
 *
 * BULK: the bulk bar's form marked data-bulk-push-review is intercepted;
 * the verdict leads ("38 ready · 12 blocked") and blockers group BY GAP,
 * each with its fix control adjacent. A fix POSTs, the review refetches,
 * the counts move. Push submits only the ready ids, honestly labelled.
 *
 * SECURITY: every node is createElement + textContent. Nothing returned
 * by the endpoints is ever interpreted as markup.
 */
(function () {
    'use strict';

    const scrim = document.querySelector('[data-pr-scrim]');
    const single = document.querySelector('[data-pr-single]');
    const bulk = document.querySelector('[data-pr-bulk]');
    if (!scrim || (!single && !bulk)) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function openModal(which) {
        scrim.hidden = false;
        if (single) single.hidden = which !== 'single';
        if (bulk) bulk.hidden = which !== 'bulk';
    }

    function closeModal() {
        scrim.hidden = true;
        if (single) single.hidden = true;
        if (bulk) bulk.hidden = true;
    }

    scrim.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
    document.querySelectorAll('[data-pr-close]').forEach(function (b) { b.addEventListener('click', closeModal); });

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function getJson(url) {
        return fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }

    /* ── Single ──────────────────────────────────────────────── */

    let singleForm = null;

    function fact(dl, key, value, muted) {
        const wrap = el('div', 'pr-fact');
        wrap.appendChild(el('dt', null, key));
        const dd = el('dd', null, value);
        if (muted) dd.appendChild(el('span', 'pr-mut', ' ' + muted));
        wrap.appendChild(dd);
        dl.appendChild(wrap);
    }

    function fillSingle(data) {
        single.querySelector('[data-pr-sub]').textContent = data.name + (data.sku ? ' · ' + data.sku : '');
        const facts = single.querySelector('[data-pr-facts]');
        facts.textContent = '';
        fact(facts, 'Goes up at', '₱' + data.price, '(₱' + data.core_price + ' catalog, ' + data.rule + ')');
        fact(facts, 'Category', data.category || 'None');
        fact(facts, 'Couriers', data.couriers.length ? data.couriers.join(', ') : 'None');
        fact(facts, 'Images', String(data.images) + ' from the catalog product', data.images_note || undefined);
        if (data.variations > 0) fact(facts, 'Variations', String(data.variations) + ' go with it');
        single.querySelector('[data-pr-title]').value = data.title || '';
        single.querySelector('[data-pr-description]').value = data.description || '';
        single.querySelector('[data-pr-listing-link]').setAttribute('href', data.listing_url);
    }

    single && single.querySelector('[data-pr-push]').addEventListener('click', function () {
        if (!singleForm) return;
        ['title', 'description'].forEach(function (name) {
            let input = singleForm.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                singleForm.appendChild(input);
            }
            input.value = single.querySelector('[data-pr-' + name + ']').value;
        });
        singleForm.submit();
    });

    /* CAPTURE phase on the document, so this runs before the shared
       bulk-form and slow-action handlers (channel-listings.js) get the
       event - they respect defaultPrevented, and the modal owns the
       submit from here. A fetch failure falls back to the native flow. */
    function interceptSingle(form) {
        singleForm = form;
        getJson(form.getAttribute('data-push-review')).then(function (data) {
            if (!data.ok) { form.submit(); return; }
            fillSingle(data);
            openModal('single');
        }).catch(function () { form.submit(); });
    }

    /* ── Bulk ────────────────────────────────────────────────── */

    let bulkIds = [];
    let bulkData = null;

    function postFix(payload) {
        return fetch(bulkData.fix_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        }).then(function () { return refetchBulk(); });
    }

    function refetchBulk() {
        const qs = bulkIds.map(function (id) { return 'product_ids[]=' + encodeURIComponent(id); }).join('&');
        return getJson(bulk.getAttribute('data-review-url') + '?' + qs).then(function (data) {
            if (data.ok) { bulkData = data; fillBulk(data); }
        });
    }

    function gapCard(head) {
        const card = el('div', 'pr-gap');
        card.appendChild(head);
        return card;
    }

    function gapHead(countText, strongText, tail) {
        const head = el('div', 'pr-gap__head');
        head.appendChild(el('strong', null, countText));
        head.appendChild(document.createTextNode(' need '));
        head.appendChild(el('strong', null, strongText));
        if (tail) head.appendChild(document.createTextNode(tail));
        return head;
    }

    function fillBulk(data) {
        bulk.querySelector('[data-pr-bulk-title]').textContent = 'Push ' + data.total + ' to Shopee';

        const blocked = data.total - data.already_listed - data.ready.length;
        const verdict = bulk.querySelector('[data-pr-verdict]');
        verdict.textContent = '';
        verdict.appendChild(el('span', 'pr-verdict__ok', data.ready.length + ' ready'));
        verdict.appendChild(el('span', 'pr-verdict__sep'));
        verdict.appendChild(el('span', blocked > 0 ? 'pr-verdict__bad' : 'pr-mut', blocked + ' blocked'));
        if (data.already_listed > 0) {
            verdict.appendChild(el('span', 'pr-mut', ' · ' + data.already_listed + ' already listed, updated in place'));
        }
        verdict.appendChild(el('span', 'pr-mut', ' · blocked products are skipped unless fixed below'));

        const gaps = bulk.querySelector('[data-pr-gaps]');
        gaps.textContent = '';

        data.attribute_gaps.forEach(function (gap) {
            const card = gapCard(gapHead(gap.product_ids.length + ' product' + (gap.product_ids.length === 1 ? '' : 's'), gap.attribute));
            const fix = el('div', 'pr-gap__fix');
            const input = el('input', 'x-input');
            input.placeholder = 'Answer for all ' + gap.product_ids.length;
            fix.appendChild(input);
            const apply = el('button', 'x-btn x-btn--secondary x-btn--sm', 'Answer for all ' + gap.product_ids.length);
            apply.type = 'button';
            apply.addEventListener('click', function () {
                if (!input.value.trim()) { input.focus(); return; }
                apply.disabled = true;
                postFix({ kind: 'attribute', attribute: gap.attribute, value: input.value.trim(), product_ids: gap.product_ids });
            });
            fix.appendChild(apply);
            card.appendChild(fix);
            gaps.appendChild(card);
        });

        if (data.courier_gap.product_ids.length) {
            const n = data.courier_gap.product_ids.length;
            const card = gapCard(gapHead(n + ' product' + (n === 1 ? '' : 's'), 'at least one courier'));
            const fix = el('div', 'pr-gap__fix');
            const boxes = [];
            data.courier_gap.logistics.forEach(function (ch) {
                const label = el('label', 'pr-check');
                const box = document.createElement('input');
                box.type = 'checkbox';
                box.className = 'cc-check';
                box.value = String(ch.id);
                boxes.push(box);
                label.appendChild(box);
                label.appendChild(document.createTextNode(' ' + ch.name + (ch.enabled ? '' : ' (off at Shopee)')));
                fix.appendChild(label);
            });
            const apply = el('button', 'x-btn x-btn--secondary x-btn--sm', 'Apply to all ' + n);
            apply.type = 'button';
            apply.addEventListener('click', function () {
                const chosen = boxes.filter(function (b) { return b.checked; }).map(function (b) { return Number(b.value); });
                if (!chosen.length) return;
                apply.disabled = true;
                postFix({ kind: 'couriers', logistic_ids: chosen, product_ids: data.courier_gap.product_ids });
            });
            fix.appendChild(apply);
            card.appendChild(fix);
            gaps.appendChild(card);
        }

        /* The category fixer (the owner, 2026-09-05): one card for every product
           without a category, with a search over the store's leaf categories
           and Apply to all, the way couriers are fixed. The products stay
           listed under it with their listing links, for the one-by-one case. */
        if (data.category_gaps.length) {
            const gap = data.category_gap || { product_ids: [], search_url: '', fetched: true };
            const n = data.category_gaps.length;
            const card = gapCard(gapHead(n + ' product' + (n === 1 ? '' : 's'), 'a Shopee category'));
            const fix = el('div', 'pr-gap__fix pr-cat');

            if (!gap.fetched) {
                fix.appendChild(el('span', 'pr-mut', 'No categories have been fetched for this store yet. Press Set up store on Settings > Connection, then come back.'));
            } else if (gap.product_ids.length) {
                const search = el('input', 'x-input pr-cat__search');
                search.type = 'search';
                search.autocomplete = 'off';
                search.placeholder = 'Type to search Shopee categories';
                search.setAttribute('aria-label', 'Search Shopee categories');
                /* The results are a PORTAL: appended to the body and placed at
                   the search field's screen position. Inside the modal they
                   sat in .pr-modal__body, which scrolls (overflow-y: auto),
                   and an overflow container clips everything positioned
                   inside it at its own edge - the list was cut off, and no
                   z-index can paint what a container has already clipped
                   (the owner, 2026-09-05). */
                document.querySelectorAll('.pr-cat__results').forEach(function (old) { old.remove(); });
                const results = el('div', 'pr-cat__results');
                results.setAttribute('role', 'listbox');
                results.hidden = true;
                document.body.appendChild(results);
                const chosen = el('span', 'pr-cat__chosen pr-mut', 'Nothing chosen yet');
                let chosenId = 0;
                let timer = null;

                function place() {
                    const r = search.getBoundingClientRect();
                    results.style.top = Math.round(r.bottom + 4) + 'px';
                    results.style.left = Math.round(r.left) + 'px';
                    results.style.width = Math.round(r.width) + 'px';
                    // Never taller than the room under the field, so the last option stays reachable.
                    results.style.maxHeight = Math.max(120, Math.round(window.innerHeight - r.bottom - 16)) + 'px';
                }

                function hideResults() { results.hidden = true; }

                function pick(cat) {
                    chosenId = cat.category_id;
                    chosen.textContent = cat.name + ' (#' + cat.category_id + ')';
                    chosen.className = 'pr-cat__chosen';
                    search.value = cat.name;
                    hideResults();
                    apply.disabled = false;
                }

                function draw(list) {
                    results.textContent = '';
                    if (!list.length) {
                        results.appendChild(el('span', 'pr-mut pr-cat__none', 'No leaf category matches that.'));
                    }
                    list.forEach(function (cat) {
                        const opt = el('button', 'pr-cat__opt', cat.name);
                        opt.type = 'button';
                        opt.setAttribute('role', 'option');
                        opt.appendChild(el('span', 'pr-mut', ' #' + cat.category_id));
                        opt.addEventListener('click', function () { pick(cat); });
                        results.appendChild(opt);
                    });
                    place();
                    results.hidden = false;
                }

                // The field moves when the modal scrolls or the window changes:
                // the list follows, or leaves.
                const body = bulk.querySelector('.pr-modal__body');
                if (body) body.addEventListener('scroll', function () { if (!results.hidden) place(); });
                window.addEventListener('resize', function () { if (!results.hidden) place(); });
                document.addEventListener('click', function (e) {
                    if (!results.hidden && e.target !== search && !results.contains(e.target)) hideResults();
                });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideResults(); });
                [scrim].concat(Array.prototype.slice.call(document.querySelectorAll('[data-pr-close]'))).forEach(function (b) {
                    b.addEventListener('click', hideResults);
                });

                search.addEventListener('input', function () {
                    chosenId = 0;
                    apply.disabled = true;
                    chosen.textContent = 'Nothing chosen yet';
                    chosen.className = 'pr-cat__chosen pr-mut';
                    clearTimeout(timer);
                    const q = search.value.trim();
                    if (q.length < 2) { results.hidden = true; return; }
                    timer = setTimeout(function () {
                        getJson(gap.search_url + '?q=' + encodeURIComponent(q) + '&limit=12')
                            .then(function (r) { draw((r && r.categories) || []); })
                            .catch(function () { draw([]); });
                    }, 180);
                });

                const apply = el('button', 'x-btn x-btn--secondary x-btn--sm', 'Apply to all ' + gap.product_ids.length);
                apply.type = 'button';
                apply.disabled = true;
                apply.addEventListener('click', function () {
                    if (!chosenId) { search.focus(); return; }
                    apply.disabled = true;
                    postFix({ kind: 'category', category_id: chosenId, product_ids: gap.product_ids });
                });

                const field = el('div', 'pr-cat__field');
                field.appendChild(search);
                fix.appendChild(field);
                fix.appendChild(chosen);
                fix.appendChild(apply);
            }
            card.appendChild(fix);

            const list = el('ul', 'pr-gap__list');
            data.category_gaps.forEach(function (row) {
                const item = el('li', null);
                item.appendChild(el('span', null, row.name + ' needs ' + row.label + ' '));
                const link = el('a', 'cc-quiet', 'Open its listing →');
                link.href = row.listing_url;
                item.appendChild(link);
                list.appendChild(item);
            });
            card.appendChild(list);
            gaps.appendChild(card);
        }

        data.product_level.forEach(function (row) {
            const card = gapCard((function () {
                const head = el('div', 'pr-gap__head');
                head.appendChild(el('strong', null, row.name));
                head.appendChild(document.createTextNode(' needs ' + row.labels.join(', ')));
                return head;
            })());
            card.className = 'pr-gap pr-gap--hard';
            const fix = el('div', 'pr-gap__fix');
            fix.appendChild(el('span', 'pr-mut', 'Product-level: cannot be fixed in batch. '));
            const link = el('a', 'cc-quiet', 'Fix it in the catalog →');
            link.href = row.catalog_url;
            fix.appendChild(link);
            card.appendChild(fix);
            gaps.appendChild(card);
        });

        const pushable = data.ready.length + data.already_listed;
        const skip = data.total - pushable;
        const pushBtn = bulk.querySelector('[data-pr-bulk-push]');
        pushBtn.textContent = skip > 0 ? 'Push ' + pushable + ' · skip ' + skip : 'Push ' + pushable;
        pushBtn.disabled = pushable === 0;

        const roster = bulk.querySelector('[data-pr-roster-link]');
        roster.href = data.roster_url + '?' + bulkIds.map(function (id) { return 'product_ids[]=' + encodeURIComponent(id); }).join('&');
    }

    bulk && bulk.querySelector('[data-pr-bulk-push]').addEventListener('click', function () {
        if (!bulkData) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = bulkData.push_url;
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrf;
        form.appendChild(token);
        // Push what is pushable: the ready set plus the already-listed,
        // which the bulk push updates in place (its own honest verb).
        const sendIds = {};
        bulkData.ready.forEach(function (id) { sendIds[id] = true; });
        (bulkData.already_listed_ids || []).forEach(function (id) { sendIds[id] = true; });
        Object.keys(sendIds).forEach(function (id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });

    function interceptBulk(form) {
        var checked = Array.prototype.slice.call(document.querySelectorAll('[data-row-check]'))
            .filter(function (b) { return b.checked; });
        var ids = checked.map(function (b) { return Number(b.value); }).filter(Boolean);
        if (!ids.length) return false; // nothing ticked: the shared handler says so
        bulkIds = ids;
        bulk.setAttribute('data-review-url', form.getAttribute('data-bulk-push-review'));
        refetchBulk().then(function () { openModal('bulk'); }).catch(function () {
            form.dataset.bulkSubmitting = '1';
            form.submit();
        });

        return true;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.hasAttribute('data-push-review')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            interceptSingle(form);
        } else if (form.hasAttribute('data-bulk-push-review') && bulk) {
            if (interceptBulk(form)) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }
    }, true);
})();
