function mount(card) {
    let series;

    try {
        series = JSON.parse(card.dataset.bars || 'null');
    } catch (e) {
        return;
    }

    if (!series || !Array.isArray(series.labels) || series.labels.length < 1) {
        return;
    }

    const wrap = card.querySelector('[data-bars-wrap]');
    const readout = card.querySelector('[data-bars-readout]');

    if (!wrap || !readout) {
        return;
    }

    const dateEl = readout.querySelector('[data-bars-date]');
    const moneyEl = readout.querySelector('[data-bars-money]');
    const countEl = readout.querySelector('[data-bars-count]');
    const svg = wrap.querySelector('svg');
    const n = series.labels.length;

    let frame = null;
    let current = -1;

    function mark(i) {
        if (i === current) {
            return;
        }
        card.querySelectorAll('.cw-bars__bar.is-on').forEach((bar) => bar.classList.remove('is-on'));
        card.querySelectorAll(`.cw-bars__bar[data-i="${i}"]`).forEach((bar) => bar.classList.add('is-on'));
        current = i;
    }

    function paint(clientX) {
        const box = svg.getBoundingClientRect();

        if (box.width <= 0) {
            return;
        }

        const ratio = Math.min(0.9999, Math.max(0, (clientX - box.left) / box.width));
        const i = Math.floor(ratio * n);
        const wrapBox = wrap.getBoundingClientRect();
        const x = box.left - wrapBox.left + ((i + 0.5) / n) * box.width;

        mark(i);
        dateEl.textContent = series.labels[i];
        moneyEl.textContent = series.money[i];
        countEl.textContent = series.counts[i];

        const half = readout.offsetWidth / 2;
        const left = Math.min(Math.max(x, half), wrapBox.width - half);

        readout.style.transform = `translateX(${left}px)`;
        card.classList.add('is-reading');
    }

    function onMove(event) {
        const clientX = event.clientX;

        if (frame !== null) {
            cancelAnimationFrame(frame);
        }

        frame = requestAnimationFrame(() => {
            frame = null;
            paint(clientX);
        });
    }

    function onLeave() {
        if (frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }

        mark(-1);
        card.classList.remove('is-reading');
    }

    wrap.addEventListener('pointerdown', onMove);
    wrap.addEventListener('pointermove', onMove);
    wrap.addEventListener('pointerleave', onLeave);
    wrap.addEventListener('pointercancel', onLeave);
}

function init() {
    document.querySelectorAll('[data-bars]').forEach(mount);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
