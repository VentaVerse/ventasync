/* The Listings status menu (partials/listing-status-menu). On a narrow
 * screen each group is a row you swipe; the chosen entry is scrolled into
 * view, so the row opens on what is chosen rather than on All.
 */
(function () {
    function init() {
        document.querySelectorAll('[data-lsm] .lsm-group').forEach(function (group) {
            if (group.scrollWidth <= group.clientWidth) return;
            var chosen = group.querySelector('.is-active') || group.querySelector('.is-on');
            if (!chosen) return;
            var box = group.getBoundingClientRect();
            var item = chosen.getBoundingClientRect();
            group.scrollLeft += (item.left - box.left) - (box.width - item.width) / 2;
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
