/* The Needs attention filter tabs (the owner, 2026-10-01): "if I click any of
 * the tabs, it scrolls down to ... Every order". Each tab was a link that
 * reloaded the page and jumped to an anchor, and on a long report the jump
 * landed somewhere else. Now a tab shows and hides the rows already on the
 * page: no reload, no scroll. The address still follows the tab (so a
 * reload or a shared link opens the same view), and without this script the
 * tabs are the plain links they were.
 */
(function () {
    function init(root) {
        var nav = root.querySelector('[data-po-filters]');
        if (!nav) return;
        var rows = Array.prototype.slice.call(root.querySelectorAll('tr[data-po-row]'));
        var blurb = root.querySelector('[data-po-blurb-text]');
        var empty = root.querySelector('[data-po-empty]');
        var more = root.querySelector('[data-po-more]');
        var limit = more ? parseInt(more.getAttribute('data-po-limit'), 10) || 0 : 0;
        var flag = 'all';

        var on = nav.querySelector('[aria-current]');
        if (on) flag = on.getAttribute('data-po-flag') || 'all';

        function draw() {
            var matching = rows.filter(function (tr) {
                return flag === 'all' || tr.getAttribute('data-po-flag') === flag;
            });
            rows.forEach(function (tr) { tr.hidden = true; });
            matching.forEach(function (tr, i) { tr.hidden = limit > 0 && i >= limit; });
            if (empty) empty.hidden = matching.length > 0;
            if (more) {
                more.hidden = !(limit > 0 && matching.length > limit);
                more.textContent = 'Show all ' + matching.length;
            }
        }

        nav.addEventListener('click', function (e) {
            var tab = e.target.closest('a[data-po-flag]');
            if (!tab || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
            e.preventDefault();
            flag = tab.getAttribute('data-po-flag') || 'all';
            Array.prototype.forEach.call(nav.querySelectorAll('a[data-po-flag]'), function (a) {
                var current = a === tab;
                a.classList.toggle('is-on', current);
                if (current) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
            });
            if (blurb) blurb.textContent = tab.getAttribute('data-po-blurb') || '';
            draw();
            try { window.history.replaceState(null, '', tab.href); } catch (err) { /* address stays; the view is right */ }
        });

        if (more) {
            more.addEventListener('click', function (e) {
                e.preventDefault();
                limit = 0;
                draw();
            });
        }
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-po-attention]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
