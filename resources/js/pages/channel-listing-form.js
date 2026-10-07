import { initCatalogPicker } from './channel-listings';

function initContinue(root) {
    const template = root.getAttribute('data-push-url');
    const button = root.querySelector('[data-continue]');
    const chosen = root.querySelector('[data-unmatched-id]');
    if (!template || !button || !chosen) return;

    const carried = Array.from(root.querySelectorAll('[data-carry]'));

    function refresh() {
        button.disabled = !chosen.value;
    }

    chosen.addEventListener('change', refresh);
    refresh();

    button.addEventListener('click', function () {
        const productId = chosen.value;
        if (!productId) return;

        const url = new URL(
            template.replace('__PRODUCT_ID__', encodeURIComponent(productId)),
            window.location.origin
        );

        carried.forEach(function (field) {
            const value = (field.value || '').trim();
            if (value !== '') url.searchParams.set(field.getAttribute('data-carry'), value);
        });

        window.location.href = url.toString();
    });
}

function initRunningTotal(root) {
    const base = parseFloat(root.getAttribute('data-base-price'));
    const out = root.querySelector('[data-total-value]');
    const percentInput = document.querySelector('[data-markup-percent]');
    const fixedInput = document.querySelector('[data-markup-fixed]');
    const ownInput = document.querySelector('[data-own-price]');
    if (!out || Number.isNaN(base)) return;

    function write(host, amount) {
        const node = host.querySelector('.money-primary') || host;
        const parts = node.textContent.trim().match(/^(\D*)([\d.,]+)(\D*)$/);
        const digits = amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        node.textContent = (parts ? parts[1] : '') + digits + (parts ? parts[3] : '');
    }

    function recalc() {
        const percent = parseFloat(percentInput ? percentInput.value : '') || 0;
        const fixed = parseFloat(fixedInput ? fixedInput.value : '') || 0;

        const own = parseFloat(ownInput ? ownInput.value : '');
        const start = own > 0 ? own : base;
        let total = start;
        if (percent > 0) total += (start * percent) / 100;
        if (fixed > 0) total += fixed;

        write(out, total);
    }

    if (percentInput) percentInput.addEventListener('input', recalc);
    if (ownInput) ownInput.addEventListener('input', recalc);
    if (fixedInput) fixedInput.addEventListener('input', recalc);
    recalc();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-catalog-picker]').forEach(initCatalogPicker);
    document.querySelectorAll('[data-continue-to-push]').forEach(initContinue);
    document.querySelectorAll('[data-running-total]').forEach(initRunningTotal);
});
