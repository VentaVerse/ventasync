/**
 * An instant reading for the dashboard's trend plot.
 *
 * The figures were reachable through the SVG's own <title>, which is correct
 * for assistive technology and useless for a person with a pointer: the
 * browser holds a native tooltip for roughly a second before it appears, and
 * a second is long enough that the chart reads as unresponsive rather than
 * as quiet. There is no CSS or attribute that shortens it. The only fix is
 * to stop asking the browser and draw the reading ourselves.
 *
 * So this does the smallest possible thing: on pointer move it works out
 * which period is nearest, moves a guide line and two dots onto it, and
 * fills a readout with that period's date, revenue and order count. No
 * animation, no delay, no library.
 *
 * The <title> elements stay exactly where they were. They are what a screen
 * reader reads, and removing them to avoid a duplicate would trade a
 * cosmetic overlap for an accessibility regression.
 *
 * Every figure comes pre-formatted from the server on data-plot, because
 * currency formatting belongs to App\Support\Money and not to a
 * reimplementation of it in JavaScript that would drift the first time a
 * second currency appeared.
 */

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

        // Nearest period, not the one to the left: a pointer sitting between
        // two days should read the day it is closest to, which is what a
        // person means by "hovering over the 5th".
        const ratio = Math.min(1, Math.max(0, (clientX - box.left) / box.width));
        const i = Math.round(ratio * (n - 1));

        const cardBox = card.getBoundingClientRect();
        const x = box.left - cardBox.left + (i / (n - 1)) * box.width;

        guide.style.transform = `translateX(${x}px)`;

        dots.forEach((dot) => {
            const key = dot.dataset.plotDot;
            const peak = Number(series.peaks[key]) || 0;
            const value = Number(series[key][i]) || 0;
            // Same geometry the path uses: 12px of headroom top and bottom
            // inside the plot, each series against its own peak.
            const pad = 12 / 200;
            const unit = peak <= 0 ? 0 : value / peak;
            const y = box.height * (1 - pad - unit * (1 - pad * 2));

            dot.style.transform = `translate(${x}px, ${y}px)`;
        });

        dateEl.textContent = series.labels[i];
        moneyEl.textContent = series.money[i];
        countEl.textContent = series.counts[i];

        // The readout follows the pointer but never leaves the card. Its own
        // width is read each time because the longest reading is not always
        // the first one shown.
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

        // One paint per frame. A pointermove can fire far more often than
        // the screen refreshes, and every one of these reads layout.
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

    // A touch that ends should clear the reading rather than leave the last
    // one frozen on the chart.
    svg.addEventListener('pointercancel', onLeave);
    svg.addEventListener('pointerup', onLeave);
}

/**
 * The share ring, same problem and a better place to put the answer.
 *
 * Its middle already prints the total, so a hover replaces that with the
 * segment's own figures rather than opening a panel that has to be
 * positioned and clamped. Nothing moves, nothing waits.
 */
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
        // pointerover, not pointerenter: these are stroked arcs, and moving
        // between two of them fires over/out on each rather than a single
        // enter on the parent.
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
