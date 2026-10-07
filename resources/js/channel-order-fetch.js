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
    function plural(n, one, many) { return n === 1 ? one : many; }

    function init(root) {
        var formId = root.getAttribute('data-form');
        var form = formId ? document.getElementById(formId) : null;
        var channel = root.getAttribute('data-channel') || 'the store';
        var beginUrl = root.getAttribute('data-begin-url');
        var stepUrl = root.getAttribute('data-step-url');
        var stopUrl = root.getAttribute('data-stop-url');
        var strip = root.querySelector('[data-of-strip]');
        var sheet = root.querySelector('[data-of-sheet]');
        var bars = root.querySelectorAll('[data-of-bar]');
        var fills = root.querySelectorAll('[data-of-fill]');
        var counts = root.querySelectorAll('[data-of-count]');
        var verdicts = root.querySelectorAll('[data-of-verdict]');
        var ledger = root.querySelector('[data-of-ledger]');
        var pageEl = root.querySelector('[data-of-page]');
        var sub = root.querySelector('[data-of-sheet-sub]');
        var titles = [root.querySelector('[data-of-title]'), root.querySelector('[data-of-sheet-title]')];

        var run = null;
        var driving = false;
        var stopAsked = false;

        function url(pattern, id) { return pattern.replace(/\/0(\/|$)/, '/' + id + '$1'); }

        function each(list, fn) { Array.prototype.forEach.call(list, fn); }
        function setHidden(sel, hidden) { each(root.querySelectorAll(sel), function (el) { el.hidden = hidden; }); }

        function draw() {
            if (!run) return;
            root.hidden = false;
            strip.hidden = false;
            var state = run.status;
            strip.setAttribute('data-state', state);
            var tone = state === 'running' ? '' : state;
            var known = run.total !== null && run.total > 0 ? run.total : (run.pages ? null : null);
            var p = 0;
            if (state === 'done') p = 1;
            else if (run.total && run.total > 0) p = Math.min(1, run.read / run.total);
            else if (run.pages && run.pages > 0) p = Math.min(1, run.page / run.pages);
            each(bars, function (b) {
                b.setAttribute('data-tone', tone);
                if (state === 'running' && !run.total && !run.pages) b.setAttribute('data-indeterminate', ''); else b.removeAttribute('data-indeterminate');
                b.setAttribute('aria-valuenow', String(Math.round(p * 100)));
            });
            each(fills, function (f) { f.style.setProperty('--of-p', String(p)); });

            var countText = run.read.toLocaleString() + (known ? ' of ' + run.total.toLocaleString() : '') + ' ' + plural(known ? run.total : run.read, 'order', 'orders');
            each(counts, function (c) { c.textContent = countText; });
            if (pageEl) pageEl.textContent = run.page ? ('page ' + run.page + (run.pages ? ' of ' + run.pages : '')) : '';

            var title = state === 'done' ? 'Fetched from ' + channel : (state === 'failed' ? 'Fetching orders from ' + channel + ' stopped' : 'Fetching orders from ' + channel);
            each(titles, function (t) { if (t) t.textContent = title; });
            if (sub) sub.textContent = run.date_from && run.date_to ? (run.date_from + ' to ' + run.date_to) : '';

            var verdict = '';
            if (state === 'done') verdict = root.getAttribute('data-outcome') || '';
            else if (state === 'failed') verdict = (run.last_error || channel + ' did not answer.') + ' ' + run.read.toLocaleString() + ' ' + plural(run.read, 'order is', 'orders are') + ' saved.';
            else if (state === 'stopped') verdict = 'Stopped on page ' + run.page + '. ' + run.read.toLocaleString() + ' ' + plural(run.read, 'order is', 'orders are') + ' saved.';
            each(verdicts, function (v) { v.textContent = verdict; v.hidden = verdict === ''; v.setAttribute('data-tone', tone); });

            if (ledger) {
                ledger.textContent = '';
                (run.ledger || []).forEach(function (line) {
                    var li = document.createElement('li');
                    var idx = line.indexOf(':');
                    var b = document.createElement('b');
                    b.textContent = idx > 0 ? line.slice(0, idx) : '';
                    var span = document.createElement('span');
                    span.textContent = idx > 0 ? line.slice(idx + 1).trim() : line;
                    li.appendChild(b); li.appendChild(span);
                    ledger.appendChild(li);
                });
            }

            setHidden('[data-of-stop]', state !== 'running');
            setHidden('[data-of-minimise]', state !== 'running');
            setHidden('[data-of-close]', state !== 'done');
            setHidden('[data-of-retry]', state !== 'failed');
            setHidden('[data-of-continue]', state !== 'stopped');
            setHidden('[data-of-startover]', !(state === 'stopped' || state === 'failed'));
        }

        function apply(answer) {
            if (!answer || !answer.ok || !answer.data || !answer.data.run) {
                run = run || { status: 'failed', read: 0, page: 0, ledger: [] };
                run.status = 'failed';
                run.last_error = (answer && answer.data && answer.data.message) || 'Could not reach the server. Try again in a moment.';
                draw();
                return;
            }
            run = answer.data.run;
            root.setAttribute('data-outcome', answer.data.outcome || '');
            draw();
        }

        function drive(resume) {
            if (driving || !run || run.status !== 'running') return;
            driving = true;
            post(url(stepUrl, run.id), resume ? { resume: 1 } : {}).then(function (answer) {
                driving = false;
                apply(answer);
                if (run.status === 'running') {
                    if (stopAsked) { stopAsked = false; post(url(stopUrl, run.id), {}).then(apply); return; }
                    window.setTimeout(function () { drive(false); }, 60);
                } else if (run.status === 'done') {
                    window.setTimeout(function () { if (run && run.status === 'done' && sheet.hidden) window.location.reload(); }, 1800);
                }
            }, function () {
                driving = false;
                apply(null);
            });
        }

        function begin(fields) {
            root.hidden = false; strip.hidden = false; strip.setAttribute('data-state', 'running');
            each(counts, function (c) { c.textContent = 'starting…'; });
            each(bars, function (b) { b.setAttribute('data-indeterminate', ''); b.setAttribute('data-tone', ''); });
            post(beginUrl, fields).then(function (answer) {
                apply(answer);
                if (run && run.status === 'running') drive(false);
                else if (run && run.status === 'done') window.setTimeout(function () { window.location.reload(); }, 1800);
            }, function () { apply(null); });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;
                if (!beginUrl || !stepUrl) return;
                var clicked = e.submitter || form.querySelector('[type="submit"]');
                if (clicked && clicked.getAttribute('formaction') && clicked.getAttribute('formaction') !== form.getAttribute('action')) return;
                var fields = {};
                var fd = new FormData(form);
                fd.forEach(function (v, k) { if (k !== '_token') fields[k] = v; });
                if (!fields.date_from || !fields.date_to) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                begin(fields);
            }, true);
        }

        function showSheet(open) {
            sheet.hidden = !open;
            sheet.classList.toggle('active', !!open);
        }

        root.addEventListener('click', function (e) {
            var t = e.target.closest('button');
            if (!t) return;
            if (t.hasAttribute('data-of-open')) { showSheet(true); }
            else if (t.hasAttribute('data-of-minimise')) { showSheet(false); }
            else if (t.hasAttribute('data-of-stop')) { stopAsked = true; if (!driving && run) post(url(stopUrl, run.id), {}).then(apply); t.disabled = true; window.setTimeout(function () { t.disabled = false; }, 1500); }
            else if (t.hasAttribute('data-of-close')) { showSheet(false); window.location.reload(); }
            else if (t.hasAttribute('data-of-retry') || t.hasAttribute('data-of-continue')) { if (run) { run.status = 'running'; draw(); drive(true); } }
            else if (t.hasAttribute('data-of-startover')) { showSheet(false); strip.hidden = true; root.hidden = true; run = null; if (form) { var b = form.querySelector('[type="submit"]'); if (b) b.focus(); } }
        });

        var resumable = root.getAttribute('data-resumable');
        if (resumable) {
            try { run = JSON.parse(resumable); } catch (err) { run = null; }
            if (run) {
                if (run.status === 'running') run.status = 'stopped';
                draw();
                var when = root.getAttribute('data-resumable-when');
                each(titles, function (t) { if (t) t.textContent = 'A fetch stopped on page ' + run.page + (run.pages ? ' of ' + run.pages : ''); });
                if (sub && when) sub.textContent = when + ', ' + run.date_from + ' to ' + run.date_to + '. ' + run.read.toLocaleString() + ' ' + plural(run.read, 'order is', 'orders are') + ' in.';
                showSheet(true);
            }
        }
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-order-fetch]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
