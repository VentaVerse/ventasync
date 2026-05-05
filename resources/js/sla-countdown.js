/*
 * SLA countdown updater
 *
 * Walks all elements with [data-sla-deadline] (Unix seconds) and refreshes
 * their displayed remaining time + colour class on a 30s tick.
 *
 * Tier thresholds: <0 → overdue, <4h → crit, <24h → warn, else ok.
 */

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
    if (diffSec < 0) return 'OVERDUE';

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
