/**
 * Auto-submits the orders list filter form when a status/source select
 * changes. Bundled into the shared app.js (both layouts load it), guarded
 * on the presence of #orders-filter, which only exists on
 * orders/index-v2.blade.php, so this is inert everywhere else - the same
 * guard convention confirm-modal.js already uses for its own element.
 *
 * Replaces inline onchange="this.form.submit()" attributes, which are a
 * CSP hazard (inline event-handler attributes require 'unsafe-inline' in
 * script-src, defeating the point of a CSP) and mix behaviour into a Blade
 * template. Selects opt in with data-autosubmit rather than this module
 * grabbing every <select> in the form, so a future filter that should not
 * auto-submit (e.g. one paired with its own "Apply" button) is not
 * silently wired up too.
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('orders-filter');
    if (!form) return;

    form.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            form.submit();
        });
    });
});
