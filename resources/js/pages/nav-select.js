document.addEventListener('change', function (e) {
    const select = e.target.closest('[data-nav-select]');
    if (!select || !select.value) return;
    const url = new URL(select.value, window.location.origin);
    if (url.origin === window.location.origin) window.location.assign(url.href);
});
