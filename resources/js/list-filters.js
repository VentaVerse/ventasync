/**
 * Auto-submits any list filter form when a select marked data-autosubmit
 * changes. Bundled into the shared app.js (both layouts load it) and inert
 * everywhere no such select exists, the same guard convention confirm-modal.js
 * uses for its own element.
 *
 * Originally written as orders-filter.js, gated on a single hardcoded
 * #orders-filter form id. Catalogue Products (Round B, Task 1) needed the
 * identical behaviour on its own filter form and a second copy of this file
 * gated on a second hardcoded id would have been exactly the duplication the
 * reskin is meant to retire, so this generalises to any form: no id lookup,
 * just every select carrying the opt-in attribute. A future filter that
 * should NOT auto-submit (e.g. one paired with its own "Apply" button)
 * simply does not add data-autosubmit - selects still opt in individually,
 * not every <select> in a filter form.
 *
 * Replaces inline onchange="this.form.submit()" attributes, which are a CSP
 * hazard (inline event-handler attributes require 'unsafe-inline' in
 * script-src, defeating the point of a CSP) and mix behaviour into a Blade
 * template.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) select.form.submit();
        });
    });
});
