(function () {
    'use strict';

    document.querySelectorAll('[data-category-pick]').forEach(setup);

    function setup(root) {
        const childrenUrl = root.getAttribute('data-children-url');
        const pathUrl = root.getAttribute('data-path-url');
        const searchUrl = root.getAttribute('data-search-url');
        const requiredWord = root.getAttribute('data-combo-required');

        const valueField = root.querySelector('[data-ctp-value]');
        const open = root.querySelector('[data-ctp-open]');
        const fieldText = root.querySelector('[data-ctp-field-text]');
        const say = root.querySelector('[data-ctp-say]');

        const modal = root.querySelector('[data-ctp-modal]');
        const msg = root.querySelector('[data-ctp-msg]');
        const cols = root.querySelector('[data-ctp-cols]');
        const results = root.querySelector('[data-ctp-results]');
        const search = root.querySelector('[data-ctp-search]');
        const picked = root.querySelector('[data-ctp-picked]');
        const confirm = root.querySelector('[data-ctp-confirm]');
        const cancel = root.querySelector('[data-ctp-cancel]');
        const clear = root.querySelector('[data-ctp-clear]');
        const refresh = root.querySelector('[data-ctp-refresh]');

        let saved = { id: valueField.value ? Number(valueField.value) : null, text: fieldText.textContent };
        let levels = [];
        let draft = [];
        let searchTimer = null;
        let loaded = false;

        function tell(message) {
            if (!say) return;
            say.textContent = message || '';
            say.hidden = !message;
        }

        function note(message) {
            if (msg) msg.textContent = message || '';
        }

        function get(url) {
            return fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); });
        }

        function holdToAccount() {
            if (!requiredWord) return;
            open.setCustomValidity(valueField.value ? '' : requiredWord);
        }

        function commit(id, text) {
            const next = id ? String(id) : '';
            const changed = valueField.value !== next;
            valueField.value = next;
            fieldText.textContent = text || 'No category picked';
            fieldText.classList.toggle('ctp__field-text--none', !text);
            saved = { id: id || null, text: fieldText.textContent };
            if (id) { root.setAttribute('data-chosen', String(id)); } else { root.removeAttribute('data-chosen'); }
            holdToAccount();
            if (changed) valueField.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function draftText() {
            return draft.map(function (s) { return s.name; }).join(' / ');
        }

        function drawDraft() {
            if (picked) picked.textContent = draftText();
            confirm.disabled = !(draft.length && draft[draft.length - 1].leaf);
        }

        function drawColumns() {
            cols.textContent = '';
            levels.forEach(function (level, index) {
                cols.appendChild(column(level, index));
            });
            cols.scrollLeft = cols.scrollWidth;
            drawDraft();
        }

        function column(level, index) {
            const wrap = document.createElement('div');
            wrap.className = 'ctp__col';

            const head = document.createElement('div');
            head.className = 'ctp__col-head';
            head.textContent = index === 0 ? 'All categories' : (draft[index - 1] ? draft[index - 1].name : '');
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

        function row(item, level, index) {
            const b = document.createElement('button');
            b.type = 'button';
            const on = draft[index] && draft[index].id === item.id;
            b.className = 'ctp__row' + (on ? ' is-on' : '');
            if (on) b.setAttribute('aria-current', 'true');

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
            draft = draft.slice(0, index).concat([item]);
            drawColumns();

            if (item.leaf) return;

            get(childrenUrl + '?parent=' + encodeURIComponent(item.id))
                .then(function (data) {
                    levels.push({ parent: item.id, items: data.items || [] });
                    drawColumns();
                })
                .catch(function () { note('That level could not be loaded.'); });
        }

        function openAt(id) {
            return get(pathUrl + '?id=' + encodeURIComponent(id)).then(function (data) {
                const path = data.path || [];
                draft = path.slice();
                levels = (data.levels || []).map(function (level) {
                    return { parent: level.parent, items: level.items || [] };
                });
                drawColumns();
            });
        }

        function loadRoots() {
            return get(childrenUrl).then(function (data) {
                levels = [{ parent: null, items: data.items || [] }];
                draft = [];
                drawColumns();
            });
        }

        function openPicker() {
            modal.classList.add('active');
            note('');
            closeResults();
            if (search) { search.value = ''; }

            const ready = loaded && levels.length
                ? Promise.resolve()
                : (saved.id ? openAt(saved.id) : loadRoots());

            ready.then(function () {
                loaded = true;
                if (search) search.focus();
            }).catch(function () { note('The categories could not be loaded.'); });
        }

        function closePicker() {
            modal.classList.remove('active');
            open.focus();
        }

        open.addEventListener('click', openPicker);

        cancel.addEventListener('click', function () {
            draft = [];
            levels = [];
            loaded = false;
            closePicker();
        });

        confirm.addEventListener('click', function () {
            if (confirm.disabled) return;
            commit(draft[draft.length - 1].id, draftText());
            closePicker();
        });

        if (clear) {
            clear.addEventListener('click', function () {
                commit(null, '');
                draft = [];
                levels = [];
                loaded = false;
                closePicker();
            });
        }

        modal.addEventListener('click', function (e) {
            if (e.target === modal) cancel.click();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) cancel.click();
        });

        function closeResults() {
            results.textContent = '';
            results.hidden = true;
            cols.hidden = false;
        }

        function showFound(items) {
            results.textContent = '';
            cols.hidden = true;
            if (!items.length) {
                const none = document.createElement('div');
                none.className = 'ctp__result ctp__result--none';
                none.textContent = 'Nothing matches that.';
                results.appendChild(none);
                results.hidden = false;

                return;
            }
            items.slice(0, 40).forEach(function (item) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ctp__result';
                b.setAttribute('role', 'option');
                b.textContent = item.path ? String(item.path) : item.name;
                b.addEventListener('click', function () {
                    search.value = '';
                    closeResults();
                    openAt(item.id).catch(function () { note('That category could not be opened.'); });
                });
                results.appendChild(b);
            });
            results.hidden = false;
        }

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

        if (search) {
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') e.preventDefault();
            });

            search.addEventListener('input', function () {
                const q = search.value.trim();
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
                            showFound(raws.map(function (raw) {
                                return {
                                    id: Number(raw.id !== undefined ? raw.id : raw.category_id),
                                    name: String(raw.name !== undefined ? raw.name : (raw.label || '')),
                                    path: raw.path ? String(raw.path) : null,
                                };
                            }).filter(function (item) { return item.id && item.name; }));
                        })
                        .catch(function () { closeResults(); });
                }, 200);
            });
        }

        if (refresh) {
            refresh.addEventListener('click', function () {
                const restore = refresh.textContent;
                refresh.disabled = true;
                refresh.textContent = 'Refreshing';

                fetch(refresh.getAttribute('data-refresh-url'), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            note((data && data.message) || 'Could not refresh the categories.');

                            return;
                        }
                        note(data.count + ' categories refreshed.');
                        loaded = false;
                        levels = [];
                        draft = [];

                        return loadRoots().then(function () { loaded = true; });
                    })
                    .catch(function () { note('Could not refresh the categories.'); })
                    .then(function () {
                        refresh.disabled = false;
                        refresh.textContent = restore;
                    });
            });
        }

        holdToAccount();
        tell('');
    }
})();
