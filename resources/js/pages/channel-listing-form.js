/*
 * The two "put one catalog product on a marketplace" forms.
 *
 * Round F Task 1. Shopee's "add a product to Shopee" page and Lazada's
 * "create / edit a listing" page were the last two views carrying behaviour
 * inside a <script> in their Blade, and both of them built their catalog
 * search results with `resultsDiv.innerHTML = items.map(...).join('')` over a
 * product name taken straight off the search endpoint. A catalog product
 * named `<img src=x onerror=...>` executed on both pages. That whole control
 * is gone from here: it is the shared catalog picker in
 * ./channel-listings.js, which is createElement and textContent throughout.
 *
 * What is left in this file is the two things that picker cannot do, one per
 * page:
 *
 *   1. Shopee: the Continue button, which is a navigation and not a submit.
 *      It hands the chosen product id and the three optional pre-fill values
 *      to the push form as a GET. The wire is unchanged: the same path and
 *      the same three query parameters (category_id, markup_fixed,
 *      markup_percent), each still omitted when empty.
 *
 *   2. Lazada: the running total under the markup fields. Base price plus the
 *      percentage plus the fixed amount, the same formula the server applies,
 *      recomputed as the operator types.
 *
 * Neither reads a URL or a figure out of a script literal. Both come off
 * data- attributes on the markup.
 */

import { initCatalogPicker } from './channel-listings';

/* ------------------------------------------------------------------
   1. Shopee: Continue to the push form.

   The push URL is a template with a placeholder rather than a base path
   with the id concatenated on: the original hardcoded
   url('/integrations/shopee/products') and would have broken silently on
   any change to the URI plan. Blade fills the template from route(), so
   the address plan stays in one place.
   ------------------------------------------------------------------ */

function initContinue(root) {
    const template = root.getAttribute('data-push-url');
    const button = root.querySelector('[data-continue]');
    const chosen = root.querySelector('[data-unmatched-id]');
    if (!template || !button || !chosen) return;

    // Every field whose value rides along as a query parameter, named by
    // the parameter it becomes. Empty ones are left off, exactly as before.
    const carried = Array.from(root.querySelectorAll('[data-carry]'));

    function refresh() {
        button.disabled = !chosen.value;
    }

    chosen.addEventListener('change', refresh);
    refresh();

    button.addEventListener('click', function () {
        const productId = chosen.value;
        if (!productId) return;

        const url = new URL(
            template.replace('__PRODUCT_ID__', encodeURIComponent(productId)),
            window.location.origin
        );

        carried.forEach(function (field) {
            const value = (field.value || '').trim();
            if (value !== '') url.searchParams.set(field.getAttribute('data-carry'), value);
        });

        window.location.href = url.toString();
    });
}

/* ------------------------------------------------------------------
   2. Lazada: the running total.

   Percentage first, then the fixed amount on top. Same order the group
   markup uses everywhere else in this application, and the same order the
   hint above the fields states.

   The figure is written into an element the Blade rendered with the
   currency already resolved through App\Support\Money, so this only
   replaces the number: no symbol is ever composed in JavaScript, because
   nothing here can read the default currency off the currencies table.
   ------------------------------------------------------------------ */

function initRunningTotal(root) {
    const base = parseFloat(root.getAttribute('data-base-price'));
    const out = root.querySelector('[data-total-value]');
    const percentInput = document.querySelector('[data-markup-percent]');
    const fixedInput = document.querySelector('[data-markup-fixed]');
    if (!out || Number.isNaN(base)) return;

    function recalc() {
        const percent = parseFloat(percentInput ? percentInput.value : '') || 0;
        const fixed = parseFloat(fixedInput ? fixedInput.value : '') || 0;

        let total = base;
        if (percent > 0) total += (base * percent) / 100;
        if (fixed > 0) total += fixed;

        // Rounded once, here, at the display boundary. The number input
        // posts two decimals, so a total computed from the unrounded
        // figure would disagree with what the server stores by a cent.
        out.textContent = total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    if (percentInput) percentInput.addEventListener('input', recalc);
    if (fixedInput) fixedInput.addEventListener('input', recalc);
    recalc();
}

/* ------------------------------------------------------------------
   Bind
   ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-catalog-picker]').forEach(initCatalogPicker);
    document.querySelectorAll('[data-continue-to-push]').forEach(initContinue);
    document.querySelectorAll('[data-running-total]').forEach(initRunningTotal);
});
