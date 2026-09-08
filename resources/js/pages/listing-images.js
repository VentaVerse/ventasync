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

    /* The same sentence the server writes (partials/channel-images-card).
       Two copies is one copy too many, but the card must keep answering as
       tiles move without a round trip, and the page must not flash a
       different sentence on load - so when one changes, both change. */
    function sentence(count) {
        if (count === 0) {
            return 'No pictures yet.';
        }
        var sent = limit > 0 ? Math.min(count, limit) : count;
        if (limit <= 0) {
            return count + (count === 1 ? ' image goes up.' : ' images go up.');
        }
        var s = sent + ' of ' + limit + (limit === 1 ? ' image goes up' : ' images go up');
        if (sent < count) s += ', ' + (count - sent) + ' held back';
        return s + '.';
    }

    /* Every visible fact about the list is derived from its order, so one
       function answers for all of them and nothing can drift apart. */
    function refresh() {
        var items = Array.prototype.slice.call(list.children);
        var focused = document.activeElement && document.activeElement.closest
            ? document.activeElement.closest('.lc-images__item')
            : null;
        items.forEach(function (li, i) {
            var rank = li.querySelector('.lc-images__rank');
            if (rank) rank.textContent = String(i + 1);
            var held = limit > 0 && i >= limit;
            li.classList.toggle('lc-images__item--held', held);
            li.setAttribute('aria-label', 'Picture ' + (i + 1) + ' of ' + items.length + (held ? ', held back' : ''));
            // One tab stop for the list: the focused tile, or the first.
            li.setAttribute('tabindex', li === (focused || items[0]) ? '0' : '-1');
            var drop = li.querySelector('[data-images-drop]');
            if (drop) drop.setAttribute('aria-label', 'Remove picture ' + (i + 1) + ' from this listing');
        });

        var paths = pathsNow();
        words.textContent = sentence(items.length);
        var next = paths.length === 0 || sameAsCatalog(paths) ? '' : JSON.stringify(paths);
        if (next !== input.value) {
            input.value = next;
            /* A value a script writes fires nothing on its own, and the
               page's unsaved-changes guard is listening. Without this, an
               arrangement made entirely from the keyboard left the page
               without a word. */
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        /* The Follow the catalog button appearing is the whole signal that
           this listing has an order of its own; it used to be a sentence as
           well, and the owner took it out (2026-09-09) on both the card and the
           template, so no channel inherits it. */
        if (resetBtn) resetBtn.hidden = input.value === '';
    }

    function announce(text) {
        if (say) say.textContent = text;
    }

    /* ── Arranging with a pointer ─────────────────────────────────────
     *
     * Hold a picture and it lifts under the cursor; the rest of the line
     * scooches to open a gap where it will land. Nothing SWAPS - a picture
     * dragged from ninth place to second pushes everything between them
     * along by one, which is what the operator means by moving it there
     * (the owner, 2026-09-08).
     *
     * Nothing is reordered in the DOM until the drop. During the drag every
     * tile is where it always was and is only TRANSFORMED to the slot it
     * would occupy, measured once when the drag begins. So there is no
     * reflow mid-gesture, the arithmetic is the same on a wrapped grid as
     * on one row, and letting go is the only moment the list actually
     * changes.
     */
    var drag = null;
    var HOLD_MS = 220;
    var hold = null;

    function slotsNow() {
        return Array.prototype.map.call(list.children, function (li) {
            var r = li.getBoundingClientRect();
            return { x: r.left, y: r.top, w: r.width, h: r.height };
        });
    }

    /* Where a tile sits once the dragged one has been pulled out of place
       and dropped back in at `to`: everything between the two closes up
       behind it. */
    function slotFor(i, from, to) {
        if (i === from) return to;
        if (from < to && i > from && i <= to) return i - 1;
        if (from > to && i >= to && i < from) return i + 1;
        return i;
    }

    function layout() {
        var items = drag.items;
        for (var i = 0; i < items.length; i++) {
            if (items[i] === drag.li) continue;
            var target = drag.slots[slotFor(i, drag.from, drag.to)];
            items[i].style.transform = 'translate(' + (target.x - drag.slots[i].x) + 'px, '
                + (target.y - drag.slots[i].y) + 'px)';
        }
    }

    /* The slot the dragged picture is over. Its own centre against the slot
       boxes: by the time the centre is inside a neighbour the two pictures
       overlap by more than half, which is the point where a person has
       decided. Pure border contact fires after eight pixels of travel and
       makes the whole line flicker. */
    function slotUnder(cx, cy) {
        for (var i = 0; i < drag.slots.length; i++) {
            var s = drag.slots[i];
            if (cx >= s.x && cx <= s.x + s.w && cy >= s.y && cy <= s.y + s.h) return i;
        }
        return null;
    }

    function begin(li, e) {
        var items = Array.prototype.slice.call(list.children);
        drag = {
            li: li,
            items: items,
            slots: slotsNow(),
            from: items.indexOf(li),
            pointerId: e.pointerId,
            startX: e.clientX,
            startY: e.clientY,
        };
        drag.to = drag.from;
        var r = drag.slots[drag.from];
        drag.grabX = e.clientX - r.x;
        drag.grabY = e.clientY - r.y;

        list.classList.add('is-arranging');
        li.classList.add('is-dragging');
        try { li.setPointerCapture(e.pointerId); } catch (err) { /* older engines */ }
        announce('Picture ' + (drag.from + 1) + ' picked up.');
    }

    function follow(e) {
        var r = drag.slots[drag.from];
        var dx = (e.clientX - drag.grabX) - r.x;
        var dy = (e.clientY - drag.grabY) - r.y;
        drag.li.style.transform = 'translate(' + dx + 'px, ' + dy + 'px) scale(1.06)';

        var to = slotUnder(r.x + dx + r.w / 2, r.y + dy + r.h / 2);
        if (to !== null && to !== drag.to) {
            drag.to = to;
            layout();
        }
    }

    function end() {
        if (!drag) return;
        var d = drag;
        drag = null;

        d.items.forEach(function (li) { li.style.transform = ''; });
        d.li.classList.remove('is-dragging');
        list.classList.remove('is-arranging');

        if (d.to !== d.from) {
            // The only moment the list itself changes.
            var after = d.items[d.to > d.from ? d.to : d.to - 1] || null;
            list.insertBefore(d.li, d.to > d.from ? (after ? after.nextSibling : null) : d.items[d.to]);
            refresh();
            announce('Moved to position ' + (index(d.li) + 1) + ' of ' + list.children.length + '.');
        } else {
            announce('Left where it was.');
        }
        d.li.focus();
    }

    function onPointerDown(e) {
        var li = e.target.closest ? e.target.closest('.lc-images__item') : null;
        if (!li || li.parentNode !== list) return;
        // A press on a verb is a press, never the start of a drag.
        if (e.target.closest('[data-images-drop]')) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;

        if (e.pointerType === 'mouse') {
            begin(li, e);
            return;
        }

        /* On touch the browser owns the gesture until we know it is a drag
           and not a scroll: a hold with the finger still. Moving first is a
           scroll and this bows out. */
        hold = {
            li: li, e: { clientX: e.clientX, clientY: e.clientY, pointerId: e.pointerId, pointerType: e.pointerType },
            timer: window.setTimeout(function () {
                hold = null;
                begin(li, { clientX: e.clientX, clientY: e.clientY, pointerId: e.pointerId });
            }, HOLD_MS),
        };
    }

    function cancelHold() {
        if (hold) {
            window.clearTimeout(hold.timer);
            hold = null;
        }
    }

    function onPointerMove(e) {
        if (hold) {
            if (Math.abs(e.clientX - hold.e.clientX) > 6 || Math.abs(e.clientY - hold.e.clientY) > 6) {
                cancelHold();
            }
            return;
        }
        if (!drag) return;
        // The page must not pan while a picture is being carried across it.
        if (e.cancelable) e.preventDefault();
        follow(e);
    }

    function onPointerUp() {
        cancelHold();
        end();
    }

    /* ── Arranging from the keyboard ────────────────────────────────── */
    var grabbed = null;

    function onKeyDown(e) {
        var li = e.target.closest ? e.target.closest('.lc-images__item') : null;
        if (!li || li.parentNode !== list) return;
        if (e.target.closest('[data-images-drop]')) return;

        var back = e.key === 'ArrowLeft' || e.key === 'ArrowUp';
        var forward = e.key === 'ArrowRight' || e.key === 'ArrowDown';

        if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') {
            e.preventDefault();
            if (grabbed === li) {
                release(li, 'Picture dropped in position ' + (index(li) + 1) + '.');
            } else {
                if (grabbed) grabbed.classList.remove('is-grabbed');
                grabbed = li;
                li.classList.add('is-grabbed');
                announce('Picture ' + (index(li) + 1) + ' picked up. Move it with the arrow keys, then press the space bar.');
            }
            return;
        }

        if (e.key === 'Escape' && grabbed) {
            release(grabbed, 'Left where it is.');
            return;
        }

        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            remove(li);
            return;
        }

        if (!back && !forward) return;
        e.preventDefault();

        // Grabbed: the arrows move the PICTURE. Otherwise they move FOCUS,
        // which is what a one-tab-stop list owes the keyboard.
        var sibling = back ? li.previousElementSibling : li.nextElementSibling;
        if (!sibling) return;
        if (grabbed === li) {
            moveTo(li, sibling, back);
            li.focus();
            announce('Now in position ' + (index(li) + 1) + ' of ' + list.children.length + '.');
        } else {
            sibling.setAttribute('tabindex', '0');
            li.setAttribute('tabindex', '-1');
            sibling.focus();
        }
    }

    function release(li, words) {
        li.classList.remove('is-grabbed');
        grabbed = null;
        announce(words);
    }

    function remove(li) {
        var next = li.nextElementSibling || li.previousElementSibling;
        if (grabbed === li) grabbed = null;
        li.remove();
        refresh();
        announce('Picture removed. ' + words.textContent);
        if (next) {
            next.setAttribute('tabindex', '0');
            next.focus();
        }
    }

    function index(li) {
        return Array.prototype.indexOf.call(list.children, li);
    }

    /* ── Dropping and adding ────────────────────────────────────────── */
    function tile(path, url) {
        var li = document.createElement('li');
        li.className = 'lc-images__item';
        li.setAttribute('data-path', path);
        li.setAttribute('tabindex', '-1');

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

        var verbs = document.createElement('span');
        verbs.className = 'lc-images__verbs';

        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'lc-images__verb lc-images__verb--drop';
        drop.setAttribute('data-images-drop', '');
        drop.setAttribute('tabindex', '-1');
        drop.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M18 6 6 18M6 6l12 12"/></svg>';
        verbs.appendChild(drop);

        li.appendChild(verbs);

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
            remove(drop.closest('.lc-images__item'));
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
