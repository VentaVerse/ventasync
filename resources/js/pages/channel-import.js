import { initCatalogPicker } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('channel-import-progress');
    if (!overlay) return;

    document.querySelectorAll('form[data-unmatched-link]').forEach(initCatalogPicker);

    document.querySelectorAll('form[data-slow-action]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            overlay.classList.add('active');
        });
    });

    initPager();
    initLazyThumbs();

    var bulk = document.getElementById('cc-import-selected');
    if (!bulk) return;
    var bar = document.querySelector('[data-import-bar]');
    var count = document.querySelector('[data-import-count]');
    var all = document.querySelector('[data-import-check-all]');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-import-check]'));

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        if (bar) bar.hidden = n === 0;
        if (count) count.textContent = String(n);
        if (all) {
            all.checked = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        var one = bulk.getAttribute('data-confirm-one') || '';
        var many = bulk.getAttribute('data-confirm-many') || '';
        if (one && many) {
            bulk.setAttribute('data-confirm', n === 1 ? one : many.replace(':n', String(n)));
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) {
                var row = b.closest('[data-import-row]');
                if (!row || !row.hidden) b.checked = all.checked;
            });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
});

function initLazyThumbs() {
    const imgs = document.querySelectorAll('img[data-thumb-url]');
    if (!imgs.length) return;
    const load = (img) => {
        const url = img.getAttribute('data-thumb-url');
        img.removeAttribute('data-thumb-url');
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => {
                if (data && typeof data.url === 'string' && /^https:\/\//i.test(data.url)) {
                    img.src = data.url;
                    img.hidden = false;
                }
            })
            .catch(() => {});
    };
    if (!('IntersectionObserver' in window)) {
        imgs.forEach(load);
        return;
    }
    const seen = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            seen.unobserve(entry.target);
            load(entry.target);
        });
    }, { rootMargin: '200px' });
    imgs.forEach((img) => seen.observe(img));
}

function initPager() {
    var pager = document.querySelector('[data-import-pager]');
    if (!pager) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-import-row]'));
    var per = parseInt(pager.getAttribute('data-per-page'), 10) || 50;
    var pages = Math.ceil(rows.length / per);
    if (pages < 2) return;

    var countEl = pager.querySelector('[data-pager-count]');
    var prev = pager.querySelector('[data-pager-prev]');
    var next = pager.querySelector('[data-pager-next]');
    var list = pager.querySelector('[data-pager-pages]');
    var current = 1;

    function numbers(cur, last) {
        var out = [];
        for (var n = 1; n <= last; n++) {
            if (n === 1 || n === last || Math.abs(n - cur) <= 1) {
                out.push(n);
            } else if (out[out.length - 1] !== null) {
                out.push(null);
            }
        }
        return out;
    }

    function show(page) {
        current = Math.min(Math.max(1, page), pages);
        var from = (current - 1) * per;
        rows.forEach(function (row, i) { row.hidden = i < from || i >= from + per; });

        if (countEl) countEl.textContent = (from + 1) + ' to ' + Math.min(from + per, rows.length) + ' of ' + rows.length;
        prev.disabled = current === 1;
        next.disabled = current === pages;

        list.textContent = '';
        numbers(current, pages).forEach(function (n) {
            var li = document.createElement('li');
            if (n === null) {
                var gap = document.createElement('span');
                gap.className = 'x-pager__gap';
                gap.setAttribute('aria-hidden', 'true');
                gap.textContent = '\u2026';
                li.appendChild(gap);
            } else {
                var el = document.createElement(n === current ? 'span' : 'button');
                el.className = 'x-pager__page' + (n === current ? ' is-on' : '');
                if (n === current) {
                    el.setAttribute('aria-current', 'page');
                } else {
                    el.type = 'button';
                    el.addEventListener('click', function () { show(n); scrollToTop(); });
                }
                var sr = document.createElement('span');
                sr.className = 'x-sr';
                sr.textContent = 'Page ';
                el.appendChild(sr);
                el.appendChild(document.createTextNode(String(n)));
                li.appendChild(el);
            }
            list.appendChild(li);
        });

        var all = document.querySelector('[data-import-check-all]');
        if (all) {
            var visible = rows.filter(function (r) { return !r.hidden; });
            var ticked = visible.filter(function (r) { var b = r.querySelector('[data-import-check]'); return b && b.checked; }).length;
            all.checked = ticked > 0 && ticked === visible.length;
            all.indeterminate = ticked > 0 && ticked < visible.length;
        }
    }

    function scrollToTop() {
        var table = rows[0] && rows[0].closest('table');
        if (table && table.getBoundingClientRect().top < 0) {
            table.scrollIntoView({ block: 'start' });
        }
    }

    prev.addEventListener('click', function () { show(current - 1); scrollToTop(); });
    next.addEventListener('click', function () { show(current + 1); scrollToTop(); });

    pager.hidden = false;
    show(1);
}
