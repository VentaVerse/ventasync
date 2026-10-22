document.addEventListener('change', function (e) {
    const select = e.target.closest('[data-pg-template]');
    if (!select) return;
    const link = select.closest('.pg-template')?.querySelector('[data-pg-template-view]');
    if (!link) return;
    const url = select.selectedOptions[0]?.dataset.url || '';
    link.hidden = url === '';
    if (url !== '') link.href = url;
});
