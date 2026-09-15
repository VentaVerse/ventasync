/* THE IMAGE LIBRARY PICKER, for every page that needs one.
 *
 * The modal markup is partials/image-library-pick; this drives it. It was
 * written inside the listing page's Images card and stayed there, so the
 * watermark form - which needs the same browse, the same folders, the same
 * search, and one picture out of it - would have meant a second copy of a
 * hundred and fifty lines. There is one.
 *
 * SECURITY. Every option is createElement + textContent: a folder or file
 * named `<img src=x onerror=...>` is text here, not markup.
 *
 *   ImageLibrary.open(onPick, {single: true})
 *   ImageLibrary.open(null, {selected: [paths], onToggle: fn(file, inLineup)})
 *
 * onPick receives the chosen files as [{path, url, name}]. `single` moves
 * the tick instead of collecting a second, for the callers that want one
 * picture rather than a set.
 *
 * With onToggle the picker edits a lineup in place: the pictures already in
 * it open highlighted, a click adds or removes one at once and says which,
 * and the button closes the picker, since every change is already made.
 */
(function () {
    var picker = null;

function build() {
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
    var cancelBtn = modal.querySelector('[data-ilp-cancel]');
    var here = '';
    var parent = null;
    var picked = {};
    var onPick = null;
    var single = false;
    var onToggle = null;
    var selected = [];
    var msgTimer = null;

    function count() {
        return Object.keys(picked).length;
    }

    function refreshAdd() {
        if (onToggle) {
            addBtn.disabled = false;
            addBtn.textContent = 'Done';
            cancelBtn.hidden = true;
            return;
        }
        cancelBtn.hidden = false;
        addBtn.disabled = count() === 0;
        addBtn.textContent = count() === 0 ? 'Add' : 'Add ' + count();
    }

    function say(text) {
        msgEl.textContent = text;
        clearTimeout(msgTimer);
        msgTimer = setTimeout(function () { msgEl.textContent = ''; }, 2400);
    }

    function load(path, query) {
        clearTimeout(msgTimer);
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

    function mark(b, on) {
        b.classList.toggle('is-picked', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
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
            mark(b, !!picked[f.path]);
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
                if (onToggle) {
                    // The lineup itself: highlighted is in it, a click takes it in or out.
                    var on = !picked[f.path];
                    if (on) { picked[f.path] = f; } else { delete picked[f.path]; }
                    mark(b, on);
                    onToggle(f, on);
                    say(on ? 'Added to the pictures.' : 'Removed from the pictures.');
                    return;
                }
                if (single && !picked[f.path]) {
                    // One at a time: a mark is one picture, so ticking a
                    // second must move the tick rather than collect two.
                    picked = {};
                    filesEl.querySelectorAll('.is-picked').forEach(function (el) { mark(el, false); });
                }
                if (picked[f.path]) { delete picked[f.path]; } else { picked[f.path] = f; }
                mark(b, !!picked[f.path]);
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
        if (onToggle) {
            close();
            return;
        }
        var files = Object.keys(picked).map(function (k) { return picked[k]; });
        close();
        if (onPick) onPick(files);
    });
    cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) close();
    });

    /* Focus goes back to whatever opened the picker, so a picker opened
       from inside another dialog returns the person to where they were. */
    var returnTo = null;

    function close() {
        modal.classList.remove('active');
        picked = {};
        refreshAdd();
        if (returnTo && document.contains(returnTo)) returnTo.focus();
        returnTo = null;
    }

    function open() {
        returnTo = document.activeElement;
        picked = {};
        if (onToggle) {
            selected.forEach(function (p) { picked[p] = { path: p }; });
        }
        refreshAdd();
        modal.classList.add('active');
        load(here, '');
        searchEl.focus();
    }

    function setHandler(fn, options) {
        onPick = typeof fn === 'function' ? fn : null;
        single = !!options.single;
        onToggle = !single && typeof options.onToggle === 'function' ? options.onToggle : null;
        selected = Array.isArray(options.selected) ? options.selected.slice() : [];
    }

    return { open: open, setHandler: setHandler };
}

    window.ImageLibrary = {
        open: function (onPick, options) {
            options = options || {};
            /* Built on first use rather than on load: most pages carry no
               modal at all, and one that does may render it after this
               script has run. */
            if (!picker) {
                picker = build();
                if (!picker) return;
            }
            picker.setHandler(onPick, options);
            picker.open();
        },
    };
})();
