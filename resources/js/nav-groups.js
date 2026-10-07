const KEY = 'ventasync-nav-groups';
const EARLIER_KEY = 'xenon-nav-groups';

function read() {
    try {
        const earlier = localStorage.getItem(EARLIER_KEY);
        if (earlier !== null) {
            if (localStorage.getItem(KEY) === null) localStorage.setItem(KEY, earlier);
            localStorage.removeItem(EARLIER_KEY);
        }
        return JSON.parse(localStorage.getItem(KEY) || '{}');
    } catch (e) { return {}; }
}

function write(state) {
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { }
}

export function initNavGroups() {
    const nav = document.querySelector('[data-nav]');
    if (!nav) return;

    const stored = read();

    nav.querySelectorAll('[data-nav-group]').forEach((group) => {
        const key = group.getAttribute('data-nav-group');
        const toggle = group.querySelector('.x-nav__group-toggle');

        if (!toggle) {
            return;
        }

        const serverOpen = group.hasAttribute('data-nav-open');
        const open = serverOpen || stored[key] === true;

        group.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        // Remove the pre-paint attributes, or they outrank .is-open and keep the group stuck open.
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

    document.documentElement.removeAttribute('data-nav-restore');
}
