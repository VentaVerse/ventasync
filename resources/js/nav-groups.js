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

        group.querySelector('.x-nav__group-toggle').addEventListener('click', () => {
            const nowOpen = !group.classList.contains('is-open');
            group.classList.toggle('is-open', nowOpen);
            group.querySelector('.x-nav__group-toggle').setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            const next = read();
            next[key] = nowOpen;
            write(next);
        });
    });
}
