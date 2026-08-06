{{-- Applies the theme before first paint so there is no flash of the wrong
     theme. Resolution order: stored choice, then light. Light is the default
     rather than the OS preference, per the owner. System is still selectable in the
     toggle, and when chosen it does follow the OS and react to OS changes. --}}
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
    apply(stored || 'light');

    window.xenonTheme = {
        get: function () { try { return localStorage.getItem(KEY) || 'light'; } catch (e) { return 'light'; } },
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
