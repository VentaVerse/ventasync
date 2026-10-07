const TIER = {
    OVERDUE: 'sla-chip--overdue',
    CRIT: 'sla-chip--crit',
    WARN: 'sla-chip--warn',
    OK: 'sla-chip--ok',
};
const TIER_CLASSES = Object.values(TIER);

function tierFor(diffSec) {
    if (diffSec < 0) return TIER.OVERDUE;
    if (diffSec < 4 * 3600) return TIER.CRIT;
    if (diffSec < 24 * 3600) return TIER.WARN;
    return TIER.OK;
}

function formatRemaining(diffSec) {
    if (diffSec < 0) {
        const late = -diffSec;
        const ld = Math.floor(late / 86400);
        const lh = Math.floor((late % 86400) / 3600);
        const lm = Math.floor((late % 3600) / 60);
        if (ld > 0) return `${ld}d ${lh}h`;
        if (lh > 0) return `${lh}h ${lm}m`;
        return `${lm}m`;
    }

    const d = Math.floor(diffSec / 86400);
    const h = Math.floor((diffSec % 86400) / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    const s = diffSec % 60;

    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s.toString().padStart(2, '0')}s`;
    return `${s}s`;
}

function refreshAll() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[data-sla-deadline]').forEach((el) => {
        const deadline = parseInt(el.dataset.slaDeadline, 10);
        if (!deadline || Number.isNaN(deadline)) return;

        const diff = deadline - now;
        const tier = tierFor(diff);

        TIER_CLASSES.forEach((c) => el.classList.remove(c));
        el.classList.add(tier);

        const timeNode = el.querySelector('.sla-chip__time');
        if (timeNode) {
            timeNode.textContent = formatRemaining(diff);
        }

        const labelNode = el.querySelector('.sla-chip__label');
        if (labelNode) {
            labelNode.textContent = diff < 0
                ? 'Overdue'
                : (el.dataset.slaLabel || 'Ship by');
        }
    });
}

function start() {
    refreshAll();
    setInterval(refreshAll, 30 * 1000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
