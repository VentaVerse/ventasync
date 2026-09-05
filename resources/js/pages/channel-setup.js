/*
 * Setting up a store (the owner, 2026-09-05): runs the reads a store needs
 * before anything can be pushed - one POST per step, in order - behind a
 * progress popup. Starts on its own right after an authorisation (the page
 * says so with data-setup-run) and on any [data-setup-open] press.
 *
 * Bound to the partials/channel-setup root; inert on pages without it.
 * Every URL and label comes off the root's data attributes. Each answer is
 * a plain sentence from the server, drawn next to its step; nothing here
 * ever shows a payload.
 */

export function initChannelSetup() {
    const root = document.querySelector('[data-channel-setup]');
    if (!root) return;

    let steps = [];
    try { steps = JSON.parse(root.dataset.setupSteps || '[]'); } catch (e) { steps = []; }
    const tokenInput = root.querySelector('input[name="_token"]');
    const token = tokenInput ? tokenInput.value : '';
    const fill = root.querySelector('[data-setup-fill]');
    const bar = root.querySelector('[data-setup-bar]');
    const rows = Array.from(root.querySelectorAll('.cs-setup__step'));
    const status = root.querySelector('[data-setup-status]');
    const done = root.querySelector('[data-setup-done]');
    let running = false;
    let ranOnce = false;

    function setProgress(pct) {
        fill.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
    }

    function mark(row, state, word, note) {
        row.dataset.state = state;
        row.querySelector('[data-step-state]').textContent = word;
        row.querySelector('[data-step-note]').textContent = note || '';
    }

    async function run() {
        if (running || steps.length === 0) return;
        running = true;
        done.hidden = true;
        rows.forEach(function (row) { mark(row, 'waiting', 'Waiting', ''); });
        setProgress(0);
        root.classList.add('active');
        status.textContent = '';

        let allOk = true;
        for (let i = 0; i < steps.length; i++) {
            const row = rows[i];
            mark(row, 'running', 'Fetching…', '');
            status.textContent = 'Fetching ' + steps[i].label.toLowerCase() + '…';
            try {
                const res = await fetch(steps[i].url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                let data = null;
                try { data = await res.json(); } catch (e) { data = null; }
                if (data && data.ok) {
                    mark(row, 'done', 'Done', data.message || '');
                } else {
                    allOk = false;
                    mark(row, 'failed', 'Not done', (data && data.message) || 'The server did not answer. Try again in a moment.');
                }
            } catch (e) {
                allOk = false;
                mark(row, 'failed', 'Not done', 'Could not reach the server. Try again in a moment.');
            }
            setProgress(Math.round(((i + 1) / steps.length) * 100));
        }

        status.textContent = allOk
            ? 'This store is ready. Products can be set up and pushed now.'
            : 'Some steps did not finish. Press Set up store again, or fetch them from Catalog once the marketplace answers.';
        done.hidden = false;
        running = false;
        ranOnce = true;
        done.focus();
    }

    function close() {
        root.classList.remove('active');
        // The page behind was rendered before the reads; a reload shows the
        // categories and couriers the pickers can now offer.
        if (ranOnce) window.location.reload();
    }

    done.addEventListener('click', close);
    root.addEventListener('click', function (e) { if (e.target === root && !running) close(); });
    document.querySelectorAll('[data-setup-open]').forEach(function (btn) {
        btn.addEventListener('click', run);
    });
    if (root.dataset.setupRun === '1') run();
}

document.addEventListener('DOMContentLoaded', initChannelSetup);
