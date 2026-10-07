(function () {
    var card = null;
    var list = null;
    var input = null;
    var words = null;
    var offInput = null;
    var say = null;
    var resetBtn = null;
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

    function refresh() {
        var items = Array.prototype.slice.call(list.children);
        var focused = document.activeElement && document.activeElement.closest
            ? document.activeElement.closest('.lc-images__item')
            : null;
        items.forEach(function (li, i) {
            var rank = li.querySelector('.lc-images__rank');
            if (rank) rank.textContent = String(i + 1);
            var held = li.hasAttribute('data-off');
            li.classList.toggle('lc-images__item--held', held);
            li.setAttribute('aria-label', 'Picture ' + (i + 1) + ' of ' + items.length + (held ? ', off' : ''));
            li.setAttribute('tabindex', li === (focused || items[0]) ? '0' : '-1');
            var drop = li.querySelector('[data-images-drop]');
            if (drop) drop.setAttribute('aria-label', 'Remove picture ' + (i + 1) + ' from this listing');
        });

        if (offInput) {
            var offNow = [];
            items.forEach(function (li) {
                if (li.hasAttribute('data-off')) offNow.push(li.getAttribute('data-path'));
            });
            var offNext = offNow.length ? JSON.stringify(offNow) : '';
            if (offNext !== offInput.value) {
                offInput.value = offNext;
                offInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        var paths = pathsNow();
        var next = paths.length === 0 || sameAsCatalog(paths) ? '' : JSON.stringify(paths);
        if (next !== input.value) {
            input.value = next;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (resetBtn) resetBtn.hidden = input.value === '';
    }

    function announce(text) {
        if (say) say.textContent = text;
    }

    var drag = null;
    var HOLD_MS = 220;
    var hold = null;

    function slotsNow() {
        return Array.prototype.map.call(list.children, function (li) {
            var r = li.getBoundingClientRect();
            return { x: r.left, y: r.top, w: r.width, h: r.height };
        });
    }

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
        try { li.setPointerCapture(e.pointerId); } catch (err) { }
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
        if (e.target.closest('[data-images-drop]') || e.target.closest('[data-images-off]')) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;

        if (e.pointerType === 'mouse') {
            begin(li, e);
            return;
        }

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
        if (e.cancelable) e.preventDefault();
        follow(e);
    }

    function onPointerUp() {
        cancelHold();
        end();
    }

    var grabbed = null;

    function onKeyDown(e) {
        var li = e.target.closest ? e.target.closest('.lc-images__item') : null;
        if (!li || li.parentNode !== list) return;
        if (e.target.closest('[data-images-drop]') || e.target.closest('[data-images-off]')) return;

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
        announce('Picture removed.');
        if (next) {
            next.setAttribute('tabindex', '0');
            next.focus();
        }
    }

    function eyeIcon(isOff) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '12');
        svg.setAttribute('height', '12');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');

        var d = document.createElementNS(ns, 'path');
        d.setAttribute('d', isOff
            ? 'M9.9 4.24A9 9 0 0 1 12 4c7 0 10 8 10 8a18 18 0 0 1-2.16 3.19M6.61 6.61A18 18 0 0 0 2 12s3 8 10 8a9 9 0 0 0 5.39-1.61M2 2l20 20'
            : 'M2 12s3-8 10-8 10 8 10 8-3 8-10 8-10-8-10-8z');
        svg.appendChild(d);

        if (!isOff) {
            var c = document.createElementNS(ns, 'circle');
            c.setAttribute('cx', '12');
            c.setAttribute('cy', '12');
            c.setAttribute('r', '3');
            svg.appendChild(c);
        }

        return svg;
    }

    function toggleOff(li) {
        var isOff = li.hasAttribute('data-off');
        if (isOff) {
            li.removeAttribute('data-off');
        } else {
            li.setAttribute('data-off', '');
        }
        var verb = li.querySelector('[data-images-off]');
        if (verb) {
            verb.textContent = '';
            verb.appendChild(eyeIcon(!isOff));
        }
        refresh();
        announce(isOff ? 'Picture on.' : 'Picture off.');
    }

    function index(li) {
        return Array.prototype.indexOf.call(list.children, li);
    }

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
        held.textContent = 'Off';
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

        var off = document.createElement('button');
        off.type = 'button';
        off.className = 'lc-images__verb lc-images__verb--off';
        off.setAttribute('data-images-off', '');
        off.setAttribute('tabindex', '-1');
        off.appendChild(eyeIcon(false));
        verbs.appendChild(off);

        li.appendChild(verbs);

        return li;
    }

    function add(files) {
        var have = pathsNow();
        var added = 0;
        files.forEach(function (f) {
            if (have.indexOf(f.path) !== -1) return;
            list.appendChild(tile(f.path, f.thumb || f.url));
            have.push(f.path);
            added++;
        });
        refresh();
        return added;
    }

    function init() {
        card = document.querySelector('[data-listing-images]');
        if (!card) return;
        list = card.querySelector('[data-images-list]');
        input = card.querySelector('[data-images-input]');
        words = card.querySelector('[data-images-words]');
        offInput = card.querySelector('[data-images-off-input]');
        say = card.querySelector('[data-images-say]');
        resetBtn = card.querySelector('[data-images-reset]');
        if (!list || !input || !words) return;

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
            var off = e.target.closest('[data-images-off]');
            if (off) {
                toggleOff(off.closest('.lc-images__item'));

                return;
            }
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

        var addBtn = card.querySelector('[data-images-add]');
        if (addBtn && window.ImageLibrary && document.querySelector('[data-ilp-modal]')) {
            addBtn.addEventListener('click', function () {
                window.ImageLibrary.open(null, {
                    selected: pathsNow(),
                    onToggle: function (file, on) {
                        if (on) {
                            add([file]);
                            announce('Picture added. ' + words.textContent);
                            return;
                        }
                        var li = Array.prototype.find.call(list.children, function (el) {
                            return el.getAttribute('data-path') === file.path;
                        });
                        if (li) {
                            if (grabbed === li) grabbed = null;
                            li.remove();
                            refresh();
                            announce('Picture removed.');
                        }
                    },
                });
            });
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
