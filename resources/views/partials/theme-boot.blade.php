{{-- Applies the theme before first paint so there is no flash of the wrong
     theme. Resolution order: stored choice, then light. Light is the default
     rather than the OS preference, per the owner. System is still selectable in the
     toggle, and when chosen it does follow the OS and react to OS changes. --}}
<script>
(function () {
    var KEY = 'ventasync-theme';
    function resolve(mode) {
        if (mode === 'dark' || mode === 'light') return mode;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function apply(mode) {
        document.documentElement.setAttribute('data-theme', resolve(mode));
        document.documentElement.setAttribute('data-theme-mode', mode);
    }
    var stored = null;
    try {
        stored = localStorage.getItem(KEY);
        // A choice saved under the earlier key is carried over once.
        var earlier = localStorage.getItem('xenon-theme');
        if (!stored && earlier) { localStorage.setItem(KEY, earlier); stored = earlier; }
        if (earlier !== null) localStorage.removeItem('xenon-theme');
    } catch (e) {}
    apply(stored || 'light');

    window.ventasyncTheme = {
        get: function () { try { return localStorage.getItem(KEY) || 'light'; } catch (e) { return 'light'; } },
        set: function (mode) {
            try { localStorage.setItem(KEY, mode); } catch (e) {}
            apply(mode);
        }
    };

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (window.ventasyncTheme.get() === 'system') apply('system');
    });
})();
</script>
