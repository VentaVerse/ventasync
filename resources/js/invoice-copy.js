/*
 * Copy-to-clipboard for invoice-request fields.
 * Targets any [data-copy] button — gives a 1.5s confirmation flash.
 */
function flashCopied(btn) {
    const original = btn.textContent;
    btn.classList.add('is-copied');
    btn.textContent = 'Copied';
    setTimeout(() => {
        btn.classList.remove('is-copied');
        btn.textContent = original;
    }, 1500);
}

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    const value = btn.dataset.copy || '';
    if (!value) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(() => flashCopied(btn));
    } else {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(ta);
        flashCopied(btn);
    }
});
