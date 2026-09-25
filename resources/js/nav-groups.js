// Collapsible nav groups. State persists per user in localStorage. The group
// containing the current route is opened by the server via data-nav-open and
// that override is never written back to storage, so navigating into a group
// does not permanently change the user's preference.
const KEY = 'ventasync-nav-groups';
const EARLIER_KEY = 'xenon-nav-groups';

function read() {
    try {
        // Groups saved under the earlier key are carried over once.
        const earlier = localStorage.getItem(EARLIER_KEY);
        if (earlier !== null) {
            if (localStorage.getItem(KEY) === null) localStorage.setItem(KEY, earlier);
            localStorage.removeItem(EARLIER_KEY);
        }
        return JSON.parse(localStorage.getItem(KEY) || '{}');
    } catch (e) { return {}; }
}

function write(state) {
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
}

export function initNavGroups() {
    const nav = document.querySelector('[data-nav]');
    if (!nav) return;

    const stored = read();

    nav.querySelectorAll('[data-nav-group]').forEach((group) => {
        const key = group.getAttribute('data-nav-group');
        const toggle = group.querySelector('.x-nav__group-toggle');

        // A group with no toggle is not this script's to drive. Without this
        // guard the null dereference below threw during module evaluation,
        // which happens BEFORE Alpine.start() further down app.js - so one
        // missing button silently killed every Alpine behaviour in the
        // application: nav groups, menus, the command palette, bulk bars,
        // the product form. Fail past it instead of taking the bundle down.
        if (!toggle) {
            return;
        }

        const serverOpen = group.hasAttribute('data-nav-open');
        const open = serverOpen || stored[key] === true;

        group.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        // The pre-paint CSS in shell.css ([data-nav-open], and
        // html[data-nav-restore~=...] below) only exists so a group renders
        // open before this script runs. Both selectors outrank .is-open
        // (0,3,0 vs 0,2,0), so once the class above reflects the real state
        // they must go, or a later click that closes the group would toggle
        // .is-open off while the higher-specificity attribute selector kept
        // forcing display: flex - a dead control that never visibly closes.
        group.removeAttribute('data-nav-open');

        toggle.addEventListener('click', () => {
            const nowOpen = !group.classList.contains('is-open');
            group.classList.toggle('is-open', nowOpen);
            toggle.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            const next = read();
            next[key] = nowOpen;
            write(next);
        });
    });

    // Global restore marker from nav-boot.blade.php's inline script - same
    // reasoning as data-nav-open above, but scoped to <html> rather than a
    // single group, so it is cleared once, after every group has its real
    // .is-open class applied.
    document.documentElement.removeAttribute('data-nav-restore');
}
