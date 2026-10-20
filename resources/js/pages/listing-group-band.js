function initBand(band) {
    const holder = band.querySelector('[data-group-values]');
    const select = band.querySelector('[data-group-select]');
    const open = band.querySelector('[data-group-open]');
    if (!holder || !select || !open) return;
    let urls;
    try { urls = JSON.parse(holder.textContent || '{}').urls || {}; } catch (e) { return; }

    select.addEventListener('change', function () {
        const url = select.value ? urls[select.value] : null;
        open.hidden = !url;
        if (url) open.href = url;
    });
}

function boot() {
    document.querySelectorAll('[data-group-band]').forEach(initBand);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
