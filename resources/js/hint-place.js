const wide = window.matchMedia('(min-width: 901px)');
let current = null;

function place(btn) {
    const bubble = btn.nextElementSibling;
    if (!bubble || !bubble.classList.contains('bl-hint__bubble')) {
        return;
    }
    btn.parentElement.classList.add('bl-hint--placed');

    const r = btn.getBoundingClientRect();
    const gap = 7;
    const edge = 8;
    const w = bubble.offsetWidth;
    const h = bubble.offsetHeight;
    const room = window.innerHeight - r.bottom - gap;
    const up = room < h && (r.top - gap) > room;
    let top = up ? r.top - gap - h : r.bottom + gap;
    top = Math.max(edge, Math.min(top, window.innerHeight - h - edge));
    let left = Math.max(edge, Math.min(r.left, window.innerWidth - w - edge));

    bubble.style.top = top + 'px';
    bubble.style.left = left + 'px';
    bubble.style.right = 'auto';

    const b = bubble.getBoundingClientRect();
    if (Math.abs(b.left - left) > 1) {
        bubble.style.left = (left - (b.left - left)) + 'px';
    }
    if (Math.abs(b.top - top) > 4) {
        bubble.style.top = (top - (b.top - top)) + 'px';
    }
    current = btn;
}

function openFrom(e) {
    if (!wide.matches || !(e.target instanceof Element)) {
        return;
    }
    const btn = e.target.closest('.bl-hint__btn');
    if (btn) {
        place(btn);
    }
}

function follow() {
    if (current && (current.matches(':hover') || current === document.activeElement)) {
        place(current);
    }
}

function release() {
    document.querySelectorAll('.bl-hint--placed').forEach((hint) => {
        hint.classList.remove('bl-hint--placed');
        const bubble = hint.querySelector('.bl-hint__bubble');
        if (bubble) {
            bubble.style.top = '';
            bubble.style.left = '';
            bubble.style.right = '';
        }
    });
    current = null;
}

document.addEventListener('mouseover', openFrom);
document.addEventListener('focusin', openFrom);
window.addEventListener('scroll', follow, true);
window.addEventListener('resize', follow);
wide.addEventListener('change', () => {
    if (!wide.matches) {
        release();
    }
});
