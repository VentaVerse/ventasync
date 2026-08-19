/**
 * The desk tools, folded away on a phone.
 *
 * Measured on the Lazada packing bench at 390x844: the first order sat at
 * y=887, just past a full screen. 443px of that was two bands a packer mid
 * shift does not use - the fetch bar, which syncs the marketplace the cron
 * already syncs hourly and which holds the largest button on the screen, and
 * the filter row, which is a customer-service tool. The work itself - which
 * queue, which step - was 88px.
 *
 * So neither band is removed and neither moves. Below the width where the
 * order table stops being a table, both start folded behind one control, and
 * one tap brings them back. Above that width nothing happens at all: the
 * toggle is hidden by CSS and the bands render exactly as they always have.
 *
 * WHY NOT <details>. It is the obvious tool and it cannot do this: `open` is
 * state rather than style, so "closed on a phone, open at a desk" is not
 * expressible in a media query, and the children of a closed <details> cannot
 * be forced back with CSS.
 *
 * If this script never runs, nothing folds and the page behaves as it did
 * before. That is the correct failure: a packer sees more chrome, not less
 * page.
 */
const PHONE = '(max-width: 699px)';

function toolsIn(scope) {
    return Array.prototype.slice.call(scope.querySelectorAll('[data-desk-tool]'));
}

/**
 * Whether anything inside the folded bands is actually narrowing the list.
 *
 * A filter the operator cannot see is a filter they will not think to clear,
 * so the toggle says when one is set. Read off the controls themselves rather
 * than from a per-panel flag, because every channel names that flag
 * differently and this has to hold for all of them.
 */
function isFiltered(scope) {
    // A GET form narrows what you are looking at; a POST form does something
    // to it. Only the first kind can hide an order, so only the first kind
    // counts - which rules out the command bar, whose date pair is prefilled
    // to a fifteen-day window and is an argument to the marketplace rather
    // than a filter on the list.
    //
    // Within that form, sort and per-page are excluded too: both always hold
    // a value and neither takes an order off the list, so counting them would
    // have the toggle claiming a filter was on permanently, which is the same
    // as never claiming it.
    const narrowing = ['search', 'text', 'date'];

    return toolsIn(scope).some(function (tool) {
        const form = tool.matches('form') ? tool : tool.querySelector('form');

        if (!form || String(form.method || '').toLowerCase() !== 'get') return false;

        return Array.prototype.slice.call(form.querySelectorAll('input')).some(function (el) {
            if (el.disabled || narrowing.indexOf(el.type) === -1) return false;
            return String(el.value || '').trim() !== '';
        });
    });
}

function initDeskTools(toggle) {
    // document.body, not document: the fold is expressed as a class and
    // Document has no classList. The panel root carries [data-desk-scope]
    // where one exists; the body is the honest fallback, and only one panel
    // renders per page so the two behave identically today.
    const scope = toggle.closest('[data-desk-scope]') || document.body;
    const label = toggle.querySelector('[data-desk-label]');
    const phone = window.matchMedia(PHONE);

    // Once the operator opens the bands themselves, they stay open until the
    // page is left. Re-folding on every resize would fight someone rotating a
    // handset while typing in the search box.
    let opened = false;

    function apply() {
        const fold = phone.matches && !opened;
        scope.classList.toggle('is-desk-folded', fold);
        toggle.setAttribute('aria-expanded', fold ? 'false' : 'true');

        if (label) {
            label.textContent = isFiltered(scope)
                ? 'Find, dates and sync — filters on'
                : 'Find, dates and sync';
        }
    }

    toggle.addEventListener('click', function () {
        opened = !opened || !phone.matches;
        apply();
    });

    // addEventListener on a MediaQueryList is the modern form; addListener is
    // the fallback for engines that never learned it.
    if (phone.addEventListener) phone.addEventListener('change', apply);
    else if (phone.addListener) phone.addListener(apply);

    apply();
}

document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.slice
        .call(document.querySelectorAll('[data-desk-toggle]'))
        .forEach(initDeskTools);
});
