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
        fill.style.setProperty('--cs-setup-p', String(pct / 100));
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
        if (ranOnce) window.location.reload();
    }

    done.addEventListener('click', close);
    root.addEventListener('click', function (e) { if (e.target === root && !running) close(); });
    document.querySelectorAll('[data-setup-open]').forEach(function (btn) {
        btn.addEventListener('click', run);
    });
    if (root.dataset.setupRun === '1') run();
}

export function initChannelDelete() {
    const root = document.querySelector('[data-channel-delete]');
    if (!root) return;

    const name = root.querySelector('[data-delete-name]');
    const submit = root.querySelector('[data-delete-submit]');
    const expect = name ? (name.dataset.expect || '') : '';

    function check() {
        submit.disabled = expect === '' || name.value !== expect;
    }

    function open() {
        root.classList.add('active');
        check();
        if (name) name.focus();
    }

    function close() {
        root.classList.remove('active');
    }

    if (name) name.addEventListener('input', check);
    document.querySelectorAll('[data-delete-open]').forEach(function (btn) {
        btn.addEventListener('click', open);
    });
    root.querySelectorAll('[data-delete-close]').forEach(function (btn) {
        btn.addEventListener('click', close);
    });
    root.addEventListener('click', function (e) { if (e.target === root) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('active')) close();
    });
    check();
    if (root.dataset.deleteOpenOnLoad === '1') open();
}

document.addEventListener('DOMContentLoaded', function () {
    initChannelSetup();
    initChannelDelete();
});
