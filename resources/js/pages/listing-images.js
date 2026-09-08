/* The "Images that go up" card on a listing page, when the listing may
 * arrange its own pictures (the owner, 2026-09-08: "make this draggable to
 * arrange, and can open up media library").
 *
 * Three jobs:
 *   1. arrange - drag a tile with a pointer, or focus one, press the space
 *      bar and use the arrow keys. The order in the list IS the order the
 *      push sends, so the tiles past the channel's limit grey out as the
 *      arrangement changes rather than at page load only;
 *   2. drop a picture from this listing, and add one from the image
 *      library (partials/image-library-pick);
 *   3. submit it. One hidden input carries the paths as JSON. Empty means
 *      what an empty Content field means: this listing follows the catalog
 *      product, so an arrangement that matches the catalog's own submits
 *      as empty and the listing keeps following it.
 *
 * Markup: partials/channel-images-card.blade.php.
 */
(function () {
    var card = null;
    var list = null;
    var input = null;
    var words = null;
    var say = null;
    var resetBtn = null;
    var limit = 0;
    var catalog = [];

    function pathsNow() {
        return Array.prototype.map.call(list.children, function (li) {
            return li.getAttribute('data-path');
        });
    }

    function sameAsCatalog(paths) {
        if (paths.length !== catalog.length) return false;
        for (var i = 0; i < paths.length; i++) {
            if (paths[i] !== catalog[i].path) return false;
        }
        return true;
    }

    function sentence(count) {
        if (count === 0) {
            return "Nothing chosen, so this listing follows the catalog product's own pictures again.";
        }
        var sent = limit > 0 ? Math.min(count, limit) : count;
        var s = sent + (sent === 1 ? ' image goes up' : ' images go up');
        if (sent < count) s += ', the first ' + sent + ' of ' + count;
        return s + '.';
    }

    /* Every visible fact about the list is derived from its order, so one
       function answers for all of them and nothing can drift apart. */
    function refresh() {
        var items = Array.prototype.slice.call(list.children);
        items.forEach(function (li, i) {
            var rank = li.querySelector('.lc-images__rank');
            if (rank) rank.textContent = String(i + 1);
            var held = limit > 0 && i >= limit;
            li.classList.toggle('lc-images__item--held', held);
            li.setAttribute('aria-label', 'Picture ' + (i + 1) + ' of ' + items.length + (held ? ', held back' : ''));
            var drop = li.querySelector('[data-images-drop]');
            if (drop) drop.setAttribute('aria-label', 'Remove picture ' + (i + 1) + ' from this listing');
        });

        var paths = pathsNow();
        words.textContent = sentence(items.length);
        input.value = paths.length === 0 || sameAsCatalog(paths) ? '' : JSON.stringify(paths);
        if (resetBtn) resetBtn.hidden = input.value === '';
    }

    function announce(text) {
        if (say) say.textContent = text;
    }

    /* ── Arranging with a pointer ───────────────────────────────────── */
    var dragged = null;

    function moveTo(li, target, before) {
        if (!target || target === li) return;
        list.insertBefore(li, before ? target : target.nextSibling);
        refresh();
    }

    function onPointerDown(e) {
        var li = e.target.closest ? e.target.closest('.lc-images__item') : null;
        if (!li || li.parentNode !== list) return;
        if (e.target.closest('[data-images-drop]')) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        dragged = li;
        li.classList.add('is-dragging');
        try { li.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
    }

    function onPointerMove(e) {
        if (!dragged) return;
        // The captured tile is under the pointer itself; lift it out of the
        // hit test for one call so the tile BEHIND it is the answer.
        dragged.style.pointerEvents = 'none';
        var under = document.elementFromPoint(e.clientX, e.clientY);
        dragged.style.pointerEvents = '';
        var target = under && under.closest ? under.closest('.lc-images__item') : null;
        if (!target || target === dragged || target.parentNode !== list) return;
        var box = target.getBoundingClientRect();
        moveTo(dragged, target, e.clientX < box.left + box.width / 2);
    }

    function onPointerUp() {
        if (!dragged) return;
        dragged.classList.remove('is-dragging');
        dragged = null;
        refresh();
    }

    /* ── Arranging from the keyboard ────────────────────────────────── */
    var grabbed = null;

    function onKeyDown(e) {
        var li = e.target.closest ? e.target.closest('.lc-images__item') : null;
        if (!li || li.parentNode !== list) return;

        if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') {
            if (e.target.closest('[data-images-drop]')) return;
            e.preventDefault();
            if (grabbed === li) {
                grabbed = null;
                li.classList.remove('is-grabbed');
                announce('Picture dropped in position ' + (index(li) + 1) + '.');
            } else {
                if (grabbed) grabbed.classList.remove('is-grabbed');
                grabbed = li;
                li.classList.add('is-grabbed');
                announce('Picture ' + (index(li) + 1) + ' picked up. Move it with the arrow keys, then press the space bar.');
            }
            return;
        }

        if (e.key === 'Escape' && grabbed) {
            grabbed.classList.remove('is-grabbed');
            grabbed = null;
            announce('Left where it is.');
            return;
        }

        if (grabbed !== li) return;
        var back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
        var forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';
        if (!back && !forward) return;
        e.preventDefault();
        var sibling = back ? li.previousElementSibling : li.nextElementSibling;
        if (!sibling) return;
        moveTo(li, sibling, back);
        li.focus();
        announce('Now in position ' + (index(li) + 1) + ' of ' + list.children.length + '.');
    }

    function index(li) {
        return Array.prototype.indexOf.call(list.children, li);
    }

    /* ── Dropping and adding ────────────────────────────────────────── */
    function tile(path, url) {
        var li = document.createElement('li');
        li.className = 'lc-images__item';
        li.setAttribute('data-path', path);
        li.setAttribute('tabindex', '0');

        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.loading = 'lazy';
        img.draggable = false;
        li.appendChild(img);

        var rank = document.createElement('span');
        rank.className = 'lc-images__rank';
        li.appendChild(rank);

        var held = document.createElement('span');
        held.className = 'lc-images__held';
        held.textContent = 'Held back';
        li.appendChild(held);

        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'lc-images__drop';
        drop.setAttribute('data-images-drop', '');
        drop.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M18 6 6 18M6 6l12 12"/></svg>';
        li.appendChild(drop);

        return li;
    }

    function add(files) {
        var have = pathsNow();
        var added = 0;
        files.forEach(function (f) {
            if (have.indexOf(f.path) !== -1) return;
            list.appendChild(tile(f.path, f.url));
            have.push(f.path);
            added++;
        });
        refresh();
        return added;
    }

    /* ── The library picker ─────────────────────────────────────────── */
    function initPicker() {
        var modal = document.querySelector('[data-ilp-modal]');
        if (!modal) return null;

        var url = modal.getAttribute('data-ilp-url');
        var pathEl = modal.querySelector('[data-ilp-path]');
        var foldersEl = modal.querySelector('[data-ilp-folders]');
        var filesEl = modal.querySelector('[data-ilp-files]');
        var foldersTitle = modal.querySelector('[data-ilp-folders-title]');
        var filesTitle = modal.querySelector('[data-ilp-files-title]');
        var emptyEl = modal.querySelector('[data-ilp-empty]');
        var msgEl = modal.querySelector('[data-ilp-msg]');
        var searchEl = modal.querySelector('[data-ilp-search]');
        var upBtn = modal.querySelector('[data-ilp-up]');
        var addBtn = modal.querySelector('[data-ilp-add]');
        var here = '';
        var parent = null;
        var picked = {};

        function count() {
            return Object.keys(picked).length;
        }

        function refreshAdd() {
            addBtn.disabled = count() === 0;
            addBtn.textContent = count() === 0 ? 'Add' : 'Add ' + count();
        }

        function load(path, query) {
            msgEl.textContent = 'Reading the library…';
            var q = new URLSearchParams();
            if (query) q.set('q', query); else q.set('path', path || '');
            fetch(url + '?' + q.toString(), { headers: { Accept: 'application/json' } })
                .then(function (r) {
                    var type = r.headers.get('content-type') || '';
                    // A session that has expired answers with a login page,
                    // not with a refusal: an answer that is not JSON is one
                    // this picker cannot read, whatever its status says.
                    if (!r.ok || type.indexOf('json') === -1) {
                        return Promise.reject(new Error('The library did not answer. Reload the page and try again.'));
                    }
                    return r.json();
                })
                .then(function (data) {
                    msgEl.textContent = '';
                    here = data.path || '';
                    parent = data.parent;
                    pathEl.textContent = query ? 'Search: ' + query : '/' + here;
                    upBtn.disabled = query ? true : (parent === null || parent === undefined);
                    draw(data.folders || [], data.files || []);
                })
                .catch(function (e) {
                    msgEl.textContent = e.message;
                    foldersTitle.hidden = true;
                    filesTitle.hidden = true;
                    emptyEl.hidden = true;
                });
        }

        function draw(folders, files) {
            foldersEl.innerHTML = '';
            filesEl.innerHTML = '';
            foldersTitle.hidden = folders.length === 0;
            filesTitle.hidden = files.length === 0;
            emptyEl.hidden = folders.length !== 0 || files.length !== 0;

            folders.forEach(function (f) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'fm-pick__item';
                b.innerHTML = '<span class="fm-pick__ico"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"'
                    + ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
                    + ' stroke-linejoin="round" aria-hidden="true"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2'
                    + ' 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></svg></span>';
                var label = document.createElement('span');
                label.className = 'fm-pick__label';
                label.textContent = f.name;
                b.appendChild(label);
                b.addEventListener('click', function () { searchEl.value = ''; load(f.path, ''); });
                foldersEl.appendChild(b);
            });

            files.forEach(function (f) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'fm-pick__item';
                b.setAttribute('aria-pressed', picked[f.path] ? 'true' : 'false');
                if (picked[f.path]) b.classList.add('is-picked');
                var img = document.createElement('img');
                img.src = f.url;
                img.alt = '';
                img.loading = 'lazy';
                b.appendChild(img);
                var label = document.createElement('span');
                label.className = 'fm-pick__label';
                label.textContent = f.name;
                b.appendChild(label);
                b.addEventListener('click', function () {
                    if (picked[f.path]) { delete picked[f.path]; } else { picked[f.path] = f; }
                    b.classList.toggle('is-picked');
                    b.setAttribute('aria-pressed', picked[f.path] ? 'true' : 'false');
                    refreshAdd();
                });
                filesEl.appendChild(b);
            });
        }

        var searchTimer = null;
        searchEl.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchEl.value.trim();
            searchTimer = setTimeout(function () { load(here, q.length >= 2 ? q : ''); }, 220);
        });
        searchEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') e.preventDefault();
        });
        upBtn.addEventListener('click', function () {
            if (parent === null || parent === undefined) return;
            searchEl.value = '';
            load(parent, '');
        });
        addBtn.addEventListener('click', function () {
            var files = Object.keys(picked).map(function (k) { return picked[k]; });
            var added = add(files);
            close();
            announce(added === 1 ? 'One picture added.' : added + ' pictures added.');
        });
        modal.querySelector('[data-ilp-cancel]').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) close();
        });

        function close() {
            modal.classList.remove('active');
            picked = {};
            refreshAdd();
        }

        function open() {
            picked = {};
            refreshAdd();
            modal.classList.add('active');
            load(here, '');
            searchEl.focus();
        }

        return { open: open };
    }

    function init() {
        card = document.querySelector('[data-listing-images]');
        if (!card) return;
        list = card.querySelector('[data-images-list]');
        input = card.querySelector('[data-images-input]');
        words = card.querySelector('[data-images-words]');
        say = card.querySelector('[data-images-say]');
        resetBtn = card.querySelector('[data-images-reset]');
        if (!list || !input || !words) return;

        limit = parseInt(card.getAttribute('data-limit') || '0', 10) || 0;
        try {
            catalog = JSON.parse(card.getAttribute('data-catalog') || '[]') || [];
        } catch (e) {
            catalog = [];
        }

        list.addEventListener('pointerdown', onPointerDown);
        list.addEventListener('pointermove', onPointerMove);
        list.addEventListener('pointerup', onPointerUp);
        list.addEventListener('pointercancel', onPointerUp);
        list.addEventListener('keydown', onKeyDown);
        list.addEventListener('click', function (e) {
            var drop = e.target.closest('[data-images-drop]');
            if (!drop) return;
            var li = drop.closest('.lc-images__item');
            var next = li.nextElementSibling || li.previousElementSibling;
            li.remove();
            refresh();
            announce('Picture removed. ' + words.textContent);
            if (next) next.focus();
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                list.innerHTML = '';
                catalog.forEach(function (t) { list.appendChild(tile(t.path, t.url)); });
                refresh();
                announce("Following the catalog product's pictures again.");
            });
        }

        var picker = initPicker();
        var addBtn = card.querySelector('[data-images-add]');
        if (addBtn && picker) {
            addBtn.addEventListener('click', picker.open);
        } else if (addBtn) {
            addBtn.hidden = true;
        }

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
