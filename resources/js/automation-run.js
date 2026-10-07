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
    function url(pattern, id) { return pattern.replace(/\/0(\/|$)/, '/' + id + '$1'); }

    function init(root) {
        var stepUrl = root.getAttribute('data-step-url');
        var stopUrl = root.getAttribute('data-stop-url');
        var modal = root.querySelector('[data-ar-modal]');
        var spinner = root.querySelector('[data-ar-spinner]');
        var markDone = root.querySelector('[data-ar-mark-done]');
        var markFailed = root.querySelector('[data-ar-mark-failed]');
        var title = root.querySelector('[data-ar-title]');
        var text = root.querySelector('[data-ar-text]');
        var stopBtn = root.querySelector('[data-ar-stop]');
        var okBtn = root.querySelector('[data-ar-ok]');
        function setHidden(el, hidden) { if (!el) return; if (hidden) el.setAttribute('hidden', ''); else el.removeAttribute('hidden'); }

        var run = null;
        var jobName = '';
        var driving = false;
        var stopAsked = false;

        function draw(state, sentence) {
            root.classList.add('active');
            modal.setAttribute('data-state', state);
            setHidden(spinner, state !== 'running');
            setHidden(markDone, state !== 'done');
            setHidden(markFailed, state !== 'failed' && state !== 'stopped');
            title.textContent = state === 'running' ? 'Running ' + jobName
                : (state === 'done' ? jobName + ' finished' : (state === 'stopped' ? jobName + ' stopped' : 'Run failed: ' + jobName));
            text.textContent = state === 'running' ? '' : (sentence || '');
            stopBtn.hidden = state !== 'running';
            okBtn.hidden = state === 'running';
            if (state !== 'running') okBtn.focus();
        }

        function apply(answer) {
            if (!answer || !answer.ok || !answer.data) {
                run = null;
                draw('failed', (answer && answer.data && answer.data.message) || 'Could not reach the server. Try again in a moment.');
                return;
            }
            if (answer.data.done && !answer.data.run) {
                run = null;
                draw(answer.data.ok ? 'done' : 'failed', answer.data.message || '');
                return;
            }
            run = answer.data.run;
            if (run.status === 'running') { draw('running'); return; }
            var state = run.status === 'stopped' ? 'stopped' : (run.status === 'failed' || run.failed > 0 ? 'failed' : 'done');
            draw(state, answer.data.outcome || '');
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

        Array.prototype.forEach.call(document.querySelectorAll('form[id^="automation-run-"]'), function (form) {
            form.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                jobName = form.getAttribute('data-run-name') || 'this job';
                run = null;
                stopAsked = false;
                draw('running');
                post(form.getAttribute('action'), {}).then(function (answer) {
                    apply(answer);
                    if (run && run.status === 'running') drive();
                }, function () { apply(null); });
            }, true);
        });

        root.addEventListener('click', function (e) {
            var t = e.target.closest('button');
            if (!t) return;
            if (t.hasAttribute('data-ar-stop')) {
                stopAsked = true;
                if (!driving && run && run.id) post(url(stopUrl, run.id), {}).then(apply);
                t.disabled = true;
                window.setTimeout(function () { t.disabled = false; }, 1500);
            } else if (t.hasAttribute('data-ar-ok')) {
                root.classList.remove('active');
                window.location.reload();
            }
        });
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-automation-run]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
