const PHONE = '(max-width: 699px)';

function toolsIn(scope) {
    return Array.prototype.slice.call(scope.querySelectorAll('[data-desk-tool]'));
}

function isFiltered(scope) {
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
    const scope = toggle.closest('[data-desk-scope]') || document.body;
    const label = toggle.querySelector('[data-desk-label]');
    const phone = window.matchMedia(PHONE);

    let opened = false;

    function apply() {
        const fold = phone.matches && !opened;
        scope.classList.toggle('is-desk-folded', fold);
        toggle.setAttribute('aria-expanded', fold ? 'false' : 'true');

        if (label) {
            label.textContent = isFiltered(scope)
                ? 'Find, dates and sync (filters on)'
                : 'Find, dates and sync';
        }
    }

    toggle.addEventListener('click', function () {
        opened = !opened || !phone.matches;
        apply();
    });

    if (phone.addEventListener) phone.addEventListener('change', apply);
    else if (phone.addListener) phone.addListener(apply);

    apply();
}

document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.slice
        .call(document.querySelectorAll('[data-desk-toggle]'))
        .forEach(initDeskTools);
});
