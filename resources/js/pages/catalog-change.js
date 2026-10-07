(function () {
    function init() {
        var panel = document.querySelector('[data-lcx-panel]');
        if (!panel) return;

        var opener = null;

        function open(e) {
            opener = e && e.currentTarget ? e.currentTarget : null;
            panel.classList.add('active');
            document.body.classList.add('cc-addpanel-open');
            var first = panel.querySelector('.cc-addpanel__close');
            if (first) first.focus();
        }

        function close() {
            panel.classList.remove('active');
            document.body.classList.remove('cc-addpanel-open');
            if (opener) opener.focus();
        }

        document.querySelectorAll('[data-lcx-open]').forEach(function (b) { b.addEventListener('click', open); });
        panel.querySelectorAll('[data-lcx-close]').forEach(function (b) { b.addEventListener('click', close); });
        panel.addEventListener('click', function (e) { if (e.target === panel) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('active') && !document.querySelector('[data-ilp-modal].active')) close();
        });
        if (panel.classList.contains('active')) document.body.classList.add('cc-addpanel-open');

        var template = panel.querySelector('template[data-lcx-tile]');

        panel.querySelectorAll('[data-lcx-tiles]').forEach(function (list) {
            var input = document.getElementById(list.getAttribute('data-lcx-input'));
            var add = list.querySelector('[data-lcx-add]');

            function paths() {
                return Array.prototype.map.call(list.querySelectorAll('[data-path]'), function (t) {
                    return t.getAttribute('data-path');
                });
            }

            function sync() {
                list.querySelectorAll('.lcx-tile').forEach(function (t, i) {
                    var n = t.querySelector('.lcx-tile__n');
                    if (n) n.textContent = String(i + 1);
                    var x = t.querySelector('[data-lcx-drop]');
                    if (x) x.setAttribute('aria-label', 'Remove photo ' + (i + 1));
                });
                if (!input) return;
                input.value = JSON.stringify(paths());
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }

            function tile(file) {
                var node = template.content.firstElementChild.cloneNode(true);
                node.setAttribute('data-path', file.path);
                node.querySelector('img').setAttribute('src', file.url || '');
                return node;
            }

            list.addEventListener('click', function (e) {
                var drop = e.target.closest('[data-lcx-drop]');
                if (drop) {
                    drop.closest('[data-path]').remove();
                    sync();
                    return;
                }
                if (!e.target.closest('[data-lcx-add]') || !window.ImageLibrary || !template) return;
                window.ImageLibrary.open(null, {
                    selected: paths(),
                    onToggle: function (file, inLineup) {
                        if (!file || typeof file.path !== 'string') return;
                        var have = list.querySelector('[data-path="' + CSS.escape(file.path) + '"]');
                        if (inLineup && !have) list.insertBefore(tile(file), add);
                        if (!inLineup && have) have.remove();
                        sync();
                    }
                });
            });
        });

        var save = panel.querySelector('[data-lcx-save]');
        if (save) {
            var mark = function () {
                save.setAttribute('data-confirm', 'Save the changes to the catalog product too?');
                save.setAttribute('data-confirm-tone', 'primary');
                save.setAttribute('data-confirm-verb', 'Save');
            };
            panel.querySelectorAll('[data-lcx-catalog]').forEach(function (el) {
                el.addEventListener('input', mark);
                el.addEventListener('change', mark);
            });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
