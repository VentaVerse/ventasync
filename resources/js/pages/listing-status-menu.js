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
