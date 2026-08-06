// Collapsible nav groups. State persists per user in localStorage. The group
// containing the current route is opened by the server via data-nav-open and
// that override is never written back to storage, so navigating into a group
// does not permanently change the user's preference.
const KEY = 'xenon-nav-groups';

function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; }
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
        const serverOpen = group.hasAttribute('data-nav-open');
        const open = serverOpen || stored[key] === true;

        group.classList.toggle('is-open', open);
        group.querySelector('.x-nav__group-toggle').setAttribute('aria-expanded', open ? 'true' : 'false');

        // The pre-paint CSS in shell.css ([data-nav-open], and
        // html[data-nav-restore~=...] below) only exists so a group renders
        // open before this script runs. Both selectors outrank .is-open
        // (0,3,0 vs 0,2,0), so once the class above reflects the real state
        // they must go, or a later click that closes the group would toggle
        // .is-open off while the higher-specificity attribute selector kept
        // forcing display: flex - a dead control that never visibly closes.
        group.removeAttribute('data-nav-open');

        group.querySelector('.x-nav__group-toggle').addEventListener('click', () => {
            const nowOpen = !group.classList.contains('is-open');
            group.classList.toggle('is-open', nowOpen);
            group.querySelector('.x-nav__group-toggle').setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
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
