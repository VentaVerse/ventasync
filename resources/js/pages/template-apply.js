function syncGroup(group, list) {
    const replaces = list.querySelectorAll('input[value="replace"]');
    const picked = Array.from(replaces).filter((r) => r.checked).length;
    group.querySelector('[data-apply-all-to="replace"]').setAttribute('aria-pressed', picked === replaces.length ? 'true' : 'false');
    group.querySelector('[data-apply-all-to="keep"]').setAttribute('aria-pressed', picked === 0 ? 'true' : 'false');
}

function syncCount(form) {
    const out = form.querySelector('[data-apply-replaced]');
    if (out) out.textContent = String(form.querySelectorAll('[data-apply-field] input[value="replace"]:checked').length);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-apply-field]').forEach((list) => {
        const form = list.closest('form');
        const group = list.closest('td')?.querySelector('[data-apply-all]');
        if (group) {
            group.addEventListener('click', (e) => {
                const button = e.target.closest('[data-apply-all-to]');
                if (!button) return;
                list.querySelectorAll('input[value="' + button.dataset.applyAllTo + '"]').forEach((r) => { r.checked = true; });
                syncGroup(group, list);
                syncCount(form);
            });
            syncGroup(group, list);
        }
        list.addEventListener('change', () => {
            if (group) syncGroup(group, list);
            syncCount(form);
        });
        syncCount(form);
    });
});
