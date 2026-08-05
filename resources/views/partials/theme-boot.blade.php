{{-- Applies the theme before first paint so there is no flash of the wrong
     theme. Resolution order: stored choice, then OS, then light. --}}
<script>
(function () {
    var KEY = 'xenon-theme';
    function resolve(mode) {
        if (mode === 'dark' || mode === 'light') return mode;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function apply(mode) {
        document.documentElement.setAttribute('data-theme', resolve(mode));
        document.documentElement.setAttribute('data-theme-mode', mode);
    }
    var stored = null;
    try { stored = localStorage.getItem(KEY); } catch (e) {}
    apply(stored || 'system');

    window.xenonTheme = {
        get: function () { try { return localStorage.getItem(KEY) || 'system'; } catch (e) { return 'system'; } },
        set: function (mode) {
            try { localStorage.setItem(KEY, mode); } catch (e) {}
            apply(mode);
        }
    };

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (window.xenonTheme.get() === 'system') apply('system');
    });
})();
</script>
