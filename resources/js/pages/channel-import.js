/*
 * The Import pages (cleanup points 10-11): one page per channel under the
 * Catalog menu, every one a clone of the same queue table.
 *
 * When the queue left each listings workbench for its own page, it left the
 * workbench's JS behind: channel-listings.js is deliberately inert without
 * that page's own overlay, so nothing bound the catalog typeahead here and
 * every Link button sat permanently disabled. This module owns the import
 * pages instead, keyed on the overlay component they all include.
 *
 * The behaviour itself still lives in channel-listings.js - same picker,
 * same wire (GET search-catalog?q=, picked id into the hidden product_id).
 */
import { initCatalogPicker } from './channel-listings';

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('channel-import-progress');
    if (!overlay) return;

    document.querySelectorAll('form[data-unmatched-link]').forEach(initCatalogPicker);

    // Scan and Import are real marketplace round-trips; the overlay is the
    // sign the press landed. Bubble phase + defaultPrevented so a guarded
    // form's cancelled confirm never raises it (see busy-overlay.js).
    // confirm-modal.js re-submits an accepted form via requestSubmit(),
    // which runs these listeners again, so the overlay rises then too.
    document.querySelectorAll('form[data-slow-action]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            overlay.classList.add('active');
        });
    });

    /* Import selected (the owner, 2026-09-04): tick rows, one press imports
       them all. The checkboxes sit in the table but belong to the bulk
       form through the form="" attribute, so no id injection is needed -
       this only keeps the count honest and the button earned. */
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
        // The bar appears only once a row is ticked - the page never
        // carries a control that does nothing (the products and listings
        // pages hold the same rule). It rides sticky under the top bar.
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
            // The page in view only: a tick-all on page 3 does not silently
            // tick the 250 rows the operator has not looked at.
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

/* ------------------------------------------------------------------
   Pages of fifty (the owner, 2026-09-06: "No cap. But I want a pagination
   instead. 50 products per page.").

   The rows are never saved, so the pages are cut here over the rows the
   render carries: one fetch, one page in view, the rest hidden with their
   ticks intact. The shell is partials/channel-import-pager.blade.php and
   wears the x-pager grammar of the Products pages; this fills the count,
   draws the numbers and moves between pages. Hidden below two pages.
   ------------------------------------------------------------------ */
/* A row whose picture the store's list did not carry asks for it when it
   scrolls into view (TikTok, 2026-09-07): <img data-thumb-url hidden>, the
   endpoint answers {url}, the image shows when there is one. */
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
        // 1 ... cur-1 cur cur+1 ... last, the same window the server pager draws.
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

        // The select-all box speaks for the page in view.
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
