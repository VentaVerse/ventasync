/* Run now in steps (the owner, 2026-09-07).
 *
 * "Running Push Stock. It takes as long as the job takes. Please keep this
 * tab open." was a spinner over one request that did the whole job. Now
 * the Run now form is intercepted: the page asks the server to begin a run
 * (the first ten units ride that request), then asks for ten more per
 * request until the run is done, drawing "Product 20 of 200" on the strip
 * above the list. A job that cannot be walked in steps still runs whole,
 * and its outcome lands on the same strip. Stop ends the run after the
 * step in flight; everything already pushed stays pushed.
 *
 * Markup: the [data-automation-run] strip in partials/_automations_tab.
 */
(function () {
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; }, function () { return { ok: false, data: null }; });
        });
    }
    function singular(word) { return word.replace(/s$/, ''); }
    function url(pattern, id) { return pattern.replace(/\/0(\/|$)/, '/' + id + '$1'); }

    function init(root) {
        var stepUrl = root.getAttribute('data-step-url');
        var stopUrl = root.getAttribute('data-stop-url');
        var strip = root.querySelector('[data-of-strip]');
        var bar = root.querySelector('[data-of-bar]');
        var fill = root.querySelector('[data-of-fill]');
        var count = root.querySelector('[data-of-count]');
        var verdict = root.querySelector('[data-of-verdict]');
        var title = root.querySelector('[data-of-title]');
        var stopBtn = root.querySelector('[data-of-stop]');
        var closeBtn = root.querySelector('[data-of-close]');

        var run = null;
        var jobName = '';
        var driving = false;
        var stopAsked = false;

        function show() { root.hidden = false; }

        function draw() {
            show();
            var state = run ? run.status : 'running';
            strip.setAttribute('data-state', state);
            var tone = state === 'running' ? '' : state;
            bar.setAttribute('data-tone', tone);
            var p = 0;
            if (run && run.total > 0) p = Math.min(1, run.done / run.total);
            if (state === 'done') p = 1;
            if (run && run.total > 0) bar.removeAttribute('data-indeterminate'); else bar.setAttribute('data-indeterminate', '');
            bar.setAttribute('aria-valuenow', String(Math.round(p * 100)));
            fill.style.setProperty('--of-p', String(p));
            title.textContent = (state === 'done' ? 'Ran ' : (state === 'failed' ? 'Run failed: ' : (state === 'stopped' ? 'Stopped: ' : 'Running '))) + jobName;
            if (run && run.total > 0) {
                var word = run.done === 1 ? singular(run.label) : run.label;
                count.textContent = run.done.toLocaleString() + ' of ' + run.total.toLocaleString() + ' ' + word + (run.failed > 0 ? ', ' + run.failed + ' failed' : '');
            } else {
                count.textContent = state === 'running' ? 'working…' : '';
            }
            var text = state === 'running' ? '' : (root.getAttribute('data-outcome') || '');
            verdict.textContent = text;
            verdict.hidden = text === '';
            stopBtn.hidden = state !== 'running';
            closeBtn.hidden = state === 'running';
        }

        function apply(answer) {
            if (!answer || !answer.ok || !answer.data) {
                run = run || { status: 'failed', done: 0, total: 0, ok: 0, failed: 0, label: 'products' };
                run.status = 'failed';
                root.setAttribute('data-outcome', (answer && answer.data && answer.data.message) || 'Could not reach the server. Try again in a moment.');
                draw();
                return;
            }
            if (answer.data.done && !answer.data.run) {
                // A job that ran whole: its outcome, in one line.
                run = { status: answer.data.ok ? 'done' : 'failed', done: 0, total: 0, ok: 0, failed: 0, label: 'products' };
                root.setAttribute('data-outcome', answer.data.message || '');
                draw();
                return;
            }
            run = answer.data.run;
            root.setAttribute('data-outcome', answer.data.outcome || '');
            draw();
        }

        function drive() {
            if (driving || !run || run.status !== 'running') return;
            driving = true;
            post(url(stepUrl, run.id), {}).then(function (answer) {
                driving = false;
                apply(answer);
                if (run && run.status === 'running') {
                    if (stopAsked) { stopAsked = false; post(url(stopUrl, run.id), {}).then(apply); return; }
                    window.setTimeout(drive, 60);
                }
            }, function () { driving = false; apply(null); });
        }

        // Every Run now form on the tab begins a run through the page.
        Array.prototype.forEach.call(document.querySelectorAll('form[id^="automation-run-"]'), function (form) {
            form.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                var nameAttr = form.getAttribute('data-busy') || '';
                jobName = nameAttr.replace(/^Running\s+/, '') || 'this job';
                run = null;
                root.removeAttribute('data-outcome');
                draw();
                post(form.getAttribute('action'), {}).then(function (answer) {
                    apply(answer);
                    if (run && run.status === 'running') drive();
                }, function () { apply(null); });
            }, true);
        });

        root.addEventListener('click', function (e) {
            var t = e.target.closest('button');
            if (!t) return;
            if (t.hasAttribute('data-of-stop')) { stopAsked = true; if (!driving && run && run.id) post(url(stopUrl, run.id), {}).then(apply); t.disabled = true; window.setTimeout(function () { t.disabled = false; }, 1500); }
            else if (t.hasAttribute('data-of-close')) { window.location.reload(); }
        });
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-automation-run]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
