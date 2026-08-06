{{-- Restores nav groups the user previously expanded, before first paint,
     so they do not flash closed and then snap open. Runs as a synchronous
     inline script in <head>, same as theme-boot.blade.php, and for the same
     reason: app.js is a module script, which defers until after HTML
     parsing, so anything it applies happens after the browser has already
     painted once.

     Only one of the two cases nav-groups.js normally restores needs a
     script at all. The group containing the CURRENT route is opened by
     Blade directly, as a plain data-nav-open attribute on the element
     itself (see partials/nav.blade.php) - that is server-rendered HTML the
     browser has by the time it parses the element, so CSS keyed off it
     (layout/shell.css) needs no JS and cannot flash.

     The only case that is genuinely unknowable on the server is a DIFFERENT
     group the user expanded on an earlier visit, unrelated to today's
     route - that lives only in localStorage. This script reads it and
     writes the open keys onto <html> as data-nav-restore, a value CSS can
     match before the nav markup itself has even been parsed. Group keys are
     hand-enumerated in the matching CSS because Navigation.php's groups are
     developer authored, not data driven, the same reasoning already used
     for the per-channel classes in components/ui-controls.css. --}}
<script>
(function () {
    var KEY = 'xenon-nav-groups';
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
    var open = [];
    for (var k in stored) {
        if (Object.prototype.hasOwnProperty.call(stored, k) && stored[k] === true) {
            open.push(k);
        }
    }
    if (open.length) {
        document.documentElement.setAttribute('data-nav-restore', open.join(' '));
    }
})();
</script>
