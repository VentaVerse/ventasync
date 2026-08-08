/**
 * Marketplace reviews list (`rv-` anatomy, Round D Task 3).
 *
 * Two behaviours, both inert unless the page carries the markup.
 *
 *   1. The fetch panel, an in-place disclosure rather than a modal.
 *   2. The selection bar, which appears only once rows are ticked.
 *
 * What this module deliberately does NOT do is build the bulk payload.
 * The old page listened for the bulk form's submit and appended a hidden
 * `ids[]` input per ticked row. On layouts.shell that form also carries
 * data-confirm, and confirm-modal.js intercepts the submit, then
 * re-submits the same form once the operator agrees - so the append ran
 * twice and every id was posted twice. The row checkboxes are instead
 * `name="ids[]"` associated to the form by the `form="..."` attribute, so
 * the browser collects them natively. That is the same pattern the
 * protocol prescribes for a control inside a table row, it cannot double
 * up, and it keeps working with JavaScript off.
 */

function initFetchPanel() {
    const toggle = document.querySelector('[data-fetch-toggle]');
    if (!toggle) return;

    const panel = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!panel) return;

    toggle.addEventListener('click', function () {
        const opening = panel.hasAttribute('hidden');
        if (opening) {
            panel.removeAttribute('hidden');
            const first = panel.querySelector('input, select');
            if (first) first.focus();
        } else {
            panel.setAttribute('hidden', '');
        }
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
}

function initSelection() {
    const bar = document.querySelector('[data-bulk-bar]');
    const boxes = document.querySelectorAll('[data-review-check]');
    if (!bar || !boxes.length) return;

    const countEl = bar.querySelector('[data-bulk-count]');
    const selectAll = document.querySelector('[data-select-all]');

    function refresh() {
        let checked = 0;
        boxes.forEach(function (box) {
            if (box.checked) checked += 1;
        });

        if (checked === 0) {
            bar.setAttribute('hidden', '');
        } else {
            bar.removeAttribute('hidden');
        }

        if (countEl) {
            countEl.textContent = checked === 1 ? '1 review selected' : checked + ' reviews selected';
        }

        if (selectAll) {
            selectAll.checked = checked === boxes.length;
            selectAll.indeterminate = checked > 0 && checked < boxes.length;
        }
    }

    boxes.forEach(function (box) {
        box.addEventListener('change', refresh);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = selectAll.checked; });
            refresh();
        });
    }

    refresh();
}

document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('[data-reviews-page]')) return;

    initFetchPanel();
    initSelection();
});
