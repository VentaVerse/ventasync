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

        matches.slice(0, 50).forEach(function (option) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.name;
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

    tags.querySelectorAll('.fm-tag__x').forEach(function (button) {
        button.addEventListener('click', function () {
            const tag = button.closest('.fm-tag');
            if (tag) tag.remove();
        });
    });
}

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

    const mustPick = root.getAttribute('data-combo-required');

    function syncValidity() {
        if (mustPick === null || typeof input.setCustomValidity !== 'function') return;
        const picked = String(value.value || '').trim() !== '';
        input.setCustomValidity(picked ? '' : (mustPick || 'Pick one from the list.'));
    }

    if (extraSource) {
        extraSource.addEventListener('change', function () {
            value.value = '';
            input.value = '';
            syncValidity();
            hide();
            warm();
        });
    }

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
                    if (run !== warmRun) return;
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
    let suppressFocusSearch = false;

    function hide() {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

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
        const labelStore = root.querySelector('[data-combo-label-store]');
        if (labelStore) labelStore.value = label;
        syncValidity();
        hideAndFocusInput();
        value.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function label(item) {
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

            option.addEventListener('click', function () {
                commit(item.id, label(item));
            });

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
        value.value = '';
        syncValidity();
        window.clearTimeout(timer);
        const query = input.value.trim();
        timer = window.setTimeout(function () { search(query); }, 200);
    });

    input.addEventListener('focus', function () {
        if (suppressFocusSearch) { suppressFocusSearch = false; return; }
        search(input.value.trim());
    });

    syncValidity();

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { hide(); return; }
        if (e.key === 'Enter') { e.preventDefault(); return; }
        if (e.key === 'ArrowDown' && !results.hidden) {
            const first = optionAt(0);
            if (first) { e.preventDefault(); focusOption(first); }
        }
    });

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) hide();
    });

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

function initAttributeFetch(root) {
    const url = root.getAttribute('data-attributes-url');
    const field = root.getAttribute('data-attributes-field');
    const source = document.querySelector(root.getAttribute('data-attributes-source') || '[data-combo-value]');
    const target = root.querySelector('[data-attributes-target]');
    if (!url || !field || !source || !target) return;

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
                    root.dispatchEvent(new CustomEvent('attributes:loaded', { bubbles: true }));
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

    if (root.hasAttribute('data-attributes-autoload') && source.value) {
        load(source.value, false);
    }
}

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

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-group-filter-picker] .fm-field').forEach(initFilterPicker);
    document.querySelectorAll('[data-category-combo]').forEach(initCategoryCombo);
    document.querySelectorAll('[data-brand-picker]').forEach(initBrandPicker);
    document.querySelectorAll('[data-attributes-fetch]').forEach(initAttributeFetch);
    bindAttributeRows();
});
