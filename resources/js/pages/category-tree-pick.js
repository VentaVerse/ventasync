/* ============================================================
 * THE CATEGORY PICKER: SEARCH, AND THE TREE BEHIND IT.
 *
 * Search is the fast way in and is untouched. Browse opens the store's own
 * tree beside it, a column per level.
 *
 * A DEPTH IS NEVER ASSUMED. A column opens whenever the row picked says
 * something sits below it, and the walk stops when a row says it is a leaf.
 * Shopee reaches five levels and Lazada six, so a fixed count would strand
 * most of Lazada's catalogue.
 *
 * Columns behind the last two fold to a spine carrying their chosen name, so
 * six levels fit on one screen without scrolling sideways.
 *
 * A search result opens every column along its path, already chosen, so the
 * seller arrives able to see where they landed.
 *
 * Everything is written with textContent and element properties, never
 * innerHTML: a category name is somebody else's data.
 * ============================================================ */
(function () {
    'use strict';

    document.querySelectorAll('[data-category-pick]').forEach(setup);

    function setup(root) {
        const childrenUrl = root.getAttribute('data-children-url');
        const pathUrl = root.getAttribute('data-path-url');
        /* A channel whose categories are the store's own keeps no search
           endpoint, because its tree is small enough to walk. The field then
           filters what has been loaded instead of asking the server. */
        const searchUrl = root.getAttribute('data-search-url');

        const valueField = root.querySelector('[data-ctp-value]');
        const input = root.querySelector('[data-ctp-input]');
        const results = root.querySelector('[data-ctp-results]');
        const browse = root.querySelector('[data-ctp-browse]');
        const tree = root.querySelector('[data-ctp-tree]');
        const cols = root.querySelector('[data-ctp-cols]');
        const pathLine = root.querySelector('[data-ctp-path]');
        const say = root.querySelector('[data-ctp-say]');

        /* Every open column, outermost first: {parent, items, chosen}. The
           whole drawing is a function of this, so nothing is patched by hand. */
        let levels = [];
        let chosenPath = [];
        let searchTimer = null;

        /* Required means required on both presses. A blank category used to
           save silently while the asterisk promised otherwise, so the browser
           is asked to refuse with its own bubble on the field the seller can
           actually see. */
        const requiredWord = root.getAttribute('data-combo-required');

        function holdToAccount() {
            if (!requiredWord) return;
            input.setCustomValidity(valueField.value ? '' : requiredWord);
        }

        /* Anything that depends on the category watches the hidden field for
           a change: Lazada reads the category's attribute template, TikTok
           narrows its brand list. Setting a value in script fires nothing on
           its own, so the change is announced here. */
        function setChosen(id) {
            const next = id ? String(id) : '';
            if (valueField.value === next) return;
            valueField.value = next;
            valueField.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function tell(message) {
            if (!say) return;
            say.textContent = message || '';
            say.hidden = !message;
        }

        function get(url) {
            return fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); });
        }

        function drawPath() {
            if (!pathLine) return;
            pathLine.textContent = '';
            if (!chosenPath.length) { pathLine.hidden = true; return; }
            chosenPath.forEach(function (step, i) {
                if (i > 0) {
                    const sep = document.createElement('span');
                    sep.className = 'ctp__sep';
                    sep.setAttribute('aria-hidden', 'true');
                    sep.textContent = '/';
                    pathLine.appendChild(sep);
                }
                const part = document.createElement('span');
                part.className = i === chosenPath.length - 1 ? 'ctp__part ctp__part--last' : 'ctp__part';
                part.textContent = step.name;
                pathLine.appendChild(part);
            });
            pathLine.hidden = false;
        }

        /* The two innermost columns stay open; everything behind them folds
           to a spine, so depth costs no width. */
        function drawColumns() {
            cols.textContent = '';
            const foldBefore = Math.max(0, levels.length - 2);

            levels.forEach(function (level, index) {
                cols.appendChild(index < foldBefore ? spine(level, index) : column(level, index));
            });

            cols.scrollLeft = cols.scrollWidth;
        }

        function spine(level, index) {
            const chosen = level.items.find(function (i) { return i.id === level.chosen; });
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ctp__spine';
            b.setAttribute('aria-label', 'Back to ' + (chosen ? chosen.name : 'this level'));
            const caret = document.createElement('span');
            caret.className = 'ctp__spine-caret';
            caret.setAttribute('aria-hidden', 'true');
            caret.textContent = '‹';
            const name = document.createElement('span');
            name.className = 'ctp__spine-name';
            name.textContent = chosen ? chosen.name : '';
            b.appendChild(caret);
            b.appendChild(name);
            b.addEventListener('click', function () {
                levels = levels.slice(0, index + 1);
                drawColumns();
            });
            return b;
        }

        function column(level, index) {
            const wrap = document.createElement('div');
            wrap.className = 'ctp__col';

            const head = document.createElement('div');
            head.className = 'ctp__col-head';
            head.textContent = index === 0 ? 'All categories' : parentNameFor(index);
            wrap.appendChild(head);

            const list = document.createElement('div');
            list.className = 'ctp__list';

            if (!level.items.length) {
                const none = document.createElement('p');
                none.className = 'ctp__none';
                none.textContent = 'Nothing deeper.';
                list.appendChild(none);
            }

            level.items.forEach(function (item) {
                list.appendChild(row(item, level, index));
            });

            wrap.appendChild(list);
            return wrap;
        }

        function parentNameFor(index) {
            const above = levels[index - 1];
            if (!above) return '';
            const chosen = above.items.find(function (i) { return i.id === above.chosen; });
            return chosen ? chosen.name : '';
        }

        function row(item, level, index) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ctp__row' + (level.chosen === item.id ? ' is-on' : '');
            if (level.chosen === item.id) b.setAttribute('aria-current', 'true');

            const name = document.createElement('span');
            name.className = 'ctp__row-name';
            name.textContent = item.name;
            b.appendChild(name);

            const mark = document.createElement('span');
            mark.className = 'ctp__row-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = item.leaf ? '' : '›';
            b.appendChild(mark);

            b.addEventListener('click', function () { pick(item, index); });
            return b;
        }

        function pick(item, index) {
            levels = levels.slice(0, index + 1);
            levels[index].chosen = item.id;
            chosenPath = chosenPath.slice(0, index).concat([{ id: item.id, name: item.name }]);

            if (item.leaf) {
                setChosen(item.id);
                input.value = item.name;
                holdToAccount();
                drawPath();
                drawColumns();
                tell('');
                return;
            }

            /* Not a leaf, so nothing is chosen yet: a marketplace takes only
               the end of a branch. */
            setChosen('');
            holdToAccount();
            drawPath();
            drawColumns();

            get(childrenUrl + '?parent=' + encodeURIComponent(item.id))
                .then(function (data) {
                    levels.push({ parent: item.id, items: data.items || [], chosen: null });
                    drawColumns();
                })
                .catch(function () { tell('That level could not be loaded. Try again.'); });
        }

        function openTree() {
            tree.hidden = false;
            browse.setAttribute('aria-expanded', 'true');
            if (levels.length) { drawColumns(); return; }

            const chosen = root.getAttribute('data-chosen');
            const load = chosen
                ? get(pathUrl + '?id=' + encodeURIComponent(chosen)).then(function (data) {
                      const path = data.path || [];
                      chosenPath = path.map(function (s) { return { id: s.id, name: s.name }; });
                      levels = (data.levels || []).map(function (level, i) {
                          return { parent: level.parent, items: level.items || [], chosen: path[i] ? path[i].id : null };
                      });
                      drawPath();
                  })
                : get(childrenUrl).then(function (data) {
                      levels = [{ parent: null, items: data.items || [], chosen: null }];
                  });

            load.then(drawColumns).catch(function () { tell('The categories could not be loaded. Try again.'); });
        }

        browse.addEventListener('click', function () {
            if (tree.hidden) { openTree(); return; }
            tree.hidden = true;
            browse.setAttribute('aria-expanded', 'false');
        });

        /* Search: unchanged in spirit, but landing on a result now opens the
           whole path behind it rather than leaving the tree where it was. */
        function closeResults() {
            results.textContent = '';
            results.hidden = true;
        }

        function chooseFound(item) {
            setChosen(item.id);
            input.value = item.name;
            holdToAccount();
            closeResults();
            root.setAttribute('data-chosen', String(item.id));
            levels = [];
            openTree();
        }

        input.addEventListener('input', function () {
            const q = input.value.trim();
            setChosen('');
            holdToAccount();
            window.clearTimeout(searchTimer);
            if (q.length < 2) { closeResults(); return; }

            if (!searchUrl) {
                searchTimer = window.setTimeout(function () { showFound(filterLoaded(q)); }, 120);
                return;
            }

            searchTimer = window.setTimeout(function () {
                get(searchUrl + '?q=' + encodeURIComponent(q))
                    .then(function (data) {
                        const raws = data.items || data.categories || data || [];
                        /* Each channel's search answers in its own words, so
                           the shapes are read here and nowhere else. */
                        showFound(raws.map(function (raw) {
                            return {
                                id: Number(raw.id ?? raw.category_id),
                                name: String(raw.name ?? raw.label ?? ''),
                                path: raw.path ? String(raw.path) : null,
                            };
                        }).filter(function (item) { return item.id && item.name; }));
                    })
                    .catch(function () { closeResults(); });
            }, 200);
        });

        /** Every leaf already loaded whose name carries the words typed. */
        function filterLoaded(q) {
            const needle = q.toLowerCase();
            const seen = {};
            const out = [];
            levels.forEach(function (level) {
                (level.items || []).forEach(function (item) {
                    if (!item.leaf || seen[item.id]) return;
                    if (item.name.toLowerCase().indexOf(needle) === -1) return;
                    seen[item.id] = true;
                    out.push(item);
                });
            });

            return out;
        }

        /** Draw a set of matches, or say that nothing matched. */
        function showFound(items) {
            results.textContent = '';
            if (!items.length) {
                const none = document.createElement('div');
                none.className = 'ctp__result ctp__result--none';
                none.textContent = 'Nothing matches that.';
                results.appendChild(none);
                results.hidden = false;

                return;
            }
            items.slice(0, 20).forEach(function (item) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ctp__result';
                b.setAttribute('role', 'option');
                b.textContent = item.path ? String(item.path) : item.name;
                b.addEventListener('mousedown', function (e) { e.preventDefault(); });
                b.addEventListener('click', function () { chooseFound(item); });
                results.appendChild(b);
            });
            results.hidden = false;
        }

        input.addEventListener('blur', function () { window.setTimeout(closeResults, 120); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !results.hidden) closeResults();
        });

        holdToAccount();

        if (root.getAttribute('data-chosen')) {
            get(pathUrl + '?id=' + encodeURIComponent(root.getAttribute('data-chosen')))
                .then(function (data) {
                    chosenPath = (data.path || []).map(function (s) { return { id: s.id, name: s.name }; });
                    /* The field shows the category's own name, read from the
                       path rather than carried through the page, so a rename
                       on the marketplace shows on the next load. */
                    if (!input.value.trim() && chosenPath.length) {
                        input.value = chosenPath[chosenPath.length - 1].name;
                    }
                    drawPath();
                })
                .catch(function () { /* the field still shows the name it was saved with */ });
        }
    }
})();
