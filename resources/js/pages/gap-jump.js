/* "There is a button here. Since it is in the same page. There is no
 * redirection. But there is no pointing to the tiktok category either."
 * (the owner, 2026-09-09)
 *
 * The warning above a listing says what it still needs, and its button
 * goes to the field that fills it. When that field is on this same page,
 * a plain anchor is nearly nothing: the browser jumps a few hundred
 * pixels with no sound and no mark, and if the section is already on
 * screen it does not move at all. Pressing the button then looks exactly
 * like pressing a dead one - which TikTok's genuinely was, pointing at an
 * id the page never rendered.
 *
 * So the button POINTS: it scrolls the section into view, marks it for a
 * few seconds so the eye lands, and puts the cursor in the first control
 * the operator has to fill. Nothing here is required for the page to
 * work; without the script the anchor still jumps, which is the floor.
 */
(function () {
    /* Long enough to notice after the scroll settles, short enough not to
       become part of the page's furniture. */
    var MARK_MS = 2600;

    function pointAt(target) {
        if (!target) return;

        target.classList.remove('is-wanted');
        // Reading offsetWidth restarts the animation when the same button
        // is pressed twice; without it the second press marks nothing.
        void target.offsetWidth;
        target.classList.add('is-wanted');
        window.setTimeout(function () {
            target.classList.remove('is-wanted');
        }, MARK_MS);

        try {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (e) {
            target.scrollIntoView();
        }

        /* The first thing in there that takes a value. A combo box hides
           its real value in a hidden input and shows a search box, so
           hidden ones are skipped or the cursor lands nowhere. */
        var control = target.matches('input, select, textarea')
            ? target
            : target.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])');
        if (control) {
            // After the scroll, or the focus fights it and the page lurches.
            window.setTimeout(function () {
                try {
                    control.focus({ preventScroll: true });
                } catch (e) {
                    control.focus();
                }
            }, 260);
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-gap-jump]') : null;
        if (!link) return;

        var href = link.getAttribute('href') || '';
        if (href.charAt(0) !== '#' || href.length < 2) return;

        var target = document.getElementById(href.slice(1));
        if (!target) return; // a dead anchor: let the browser do whatever it does

        e.preventDefault();
        pointAt(target);
    });
})();
