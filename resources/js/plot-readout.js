function mount(card) {
    let series;

    try {
        series = JSON.parse(card.dataset.plot || 'null');
    } catch (e) {
        return;
    }

    if (!series || !Array.isArray(series.labels) || series.labels.length < 2) {
        return;
    }

    const svg = card.querySelector('.bl-plot');
    const guide = card.querySelector('[data-plot-guide]');
    const readout = card.querySelector('[data-plot-readout]');

    if (!svg || !guide || !readout) {
        return;
    }

    const dateEl = readout.querySelector('[data-plot-date]');
    const moneyEl = readout.querySelector('[data-plot-money]');
    const countEl = readout.querySelector('[data-plot-count]');
    const dots = card.querySelectorAll('[data-plot-dot]');
    const n = series.labels.length;

    let frame = null;

    function paint(clientX) {
        const box = svg.getBoundingClientRect();

        if (box.width <= 0) {
            return;
        }

        const ratio = Math.min(1, Math.max(0, (clientX - box.left) / box.width));
        const i = Math.round(ratio * (n - 1));

        const cardBox = card.getBoundingClientRect();
        const x = box.left - cardBox.left + (i / (n - 1)) * box.width;

        guide.style.transform = `translateX(${x}px)`;

        dots.forEach((dot) => {
            const key = dot.dataset.plotDot;
            const peak = Number(series.peaks[key]) || 0;
            const value = Number(series[key][i]) || 0;
            const pad = 12 / 200;
            const unit = peak <= 0 ? 0 : value / peak;
            const y = box.height * (1 - pad - unit * (1 - pad * 2));

            dot.style.transform = `translate(${x}px, ${y}px)`;
        });

        dateEl.textContent = series.labels[i];
        moneyEl.textContent = series.money[i];
        countEl.textContent = series.counts[i];

        const half = readout.offsetWidth / 2;
        const left = Math.min(Math.max(x, half), cardBox.width - half);

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

        card.classList.remove('is-reading');
    }

    svg.addEventListener('pointermove', onMove);
    svg.addEventListener('pointerleave', onLeave);

    svg.addEventListener('pointercancel', onLeave);
    svg.addEventListener('pointerup', onLeave);
}

function mountRing(svg) {
    const v = svg.querySelector('[data-ring-v]');
    const k = svg.querySelector('[data-ring-k]');
    const share = svg.querySelector('[data-ring-s]');

    if (!v || !k || !share) {
        return;
    }

    const idle = { v: v.textContent, k: k.textContent };

    function read(seg) {
        v.textContent = seg.dataset.ringCount.split(' ')[0];
        k.textContent = seg.dataset.ringLabel;
        share.textContent = seg.dataset.ringShare;
        svg.classList.add('is-reading');
    }

    function rest() {
        v.textContent = idle.v;
        k.textContent = idle.k;
        share.textContent = '';
        svg.classList.remove('is-reading');
    }

    svg.querySelectorAll('[data-ring-seg]').forEach((seg) => {
        seg.addEventListener('pointerover', () => read(seg));
        seg.addEventListener('focus', () => read(seg));
    });

    svg.addEventListener('pointerleave', rest);
    svg.addEventListener('blur', rest, true);
}

function init() {
    document.querySelectorAll('[data-plot]').forEach(mount);
    document.querySelectorAll('[data-ring]').forEach(mountRing);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
