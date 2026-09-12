/* Description templates (the owner, 2026-09-07).
 *
 * Two jobs, one file:
 *   1. The templates page: New and Edit open one modal, which posts to store
 *      or, with _method=PUT, to update.
 *   2. The + beside a prefix or suffix dropdown on a listing page: the same
 *      modal, posted over fetch so the page is never left. The new template
 *      joins both dropdowns and is selected in the one that asked for it.
 *
 * Markup: resources/views/channels/description-templates/index.blade.php and
 * partials/channel-description-pick.blade.php.
 */
(function () {
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function open(modal) { modal.classList.add('active'); }
    function close(modal) { modal.classList.remove('active'); }

    /* The body field may be a Summernote editor. Read and write it through
       the editor so the display stays in sync; an empty editor returns
       "<p><br></p>", which is treated as empty. */
    function editor(el) {
        return (window.jQuery && jQuery(el).data('summernote')) ? jQuery(el) : null;
    }
    function setBody(el, value) {
        var rich = editor(el);
        if (rich) rich.summernote('code', value);
        el.value = value;
    }
    function getBody(el) {
        var rich = editor(el);
        if (!rich) return el.value;
        return rich.summernote('isEmpty') ? '' : rich.summernote('code');
    }

    /* ── The templates page ─────────────────────────────────────────── */
    function initPage() {
        var modal = document.querySelector('[data-dt-modal]');
        if (!modal) return;
        var form = modal.querySelector('[data-dt-form]');
        var method = modal.querySelector('[data-dt-method]');
        var title = modal.querySelector('[data-dt-title]');
        var name = modal.querySelector('[data-dt-field-name]');
        var body = modal.querySelector('[data-dt-field-body]');
        var createAction = form.getAttribute('action');

        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-dt-new], [data-dt-edit], [data-dt-cancel]');
            if (!el) return;
            if (el.hasAttribute('data-dt-cancel')) { close(modal); return; }

            if (el.hasAttribute('data-dt-new')) {
                form.setAttribute('action', createAction);
                method.value = 'POST';
                title.textContent = 'New template';
                name.value = '';
                setBody(body, '');
            } else {
                form.setAttribute('action', el.getAttribute('data-dt-url'));
                method.value = 'PUT';
                title.textContent = 'Edit template';
                name.value = el.getAttribute('data-dt-name') || '';
                setBody(body, el.getAttribute('data-dt-body') || '');
            }
            open(modal);
            name.focus();
        });
    }

    /* ── The + on a listing page ────────────────────────────────────── */
    function initPick() {
        var modal = document.querySelector('[data-dtp-modal]');
        if (!modal) return;
        var url = modal.getAttribute('data-dtp-url');
        var name = modal.querySelector('[data-dtp-name]');
        var body = modal.querySelector('[data-dtp-body]');
        var save = modal.querySelector('[data-dtp-save]');
        var error = modal.querySelector('[data-dtp-error]');
        var asking = null;   // the select that opened it

        document.addEventListener('click', function (e) {
            var add = e.target.closest('[data-dtp-add]');
            if (add) {
                asking = document.getElementById(add.getAttribute('data-dtp-for'));
                name.value = '';
                setBody(body, '');
                error.textContent = '';
                open(modal);
                name.focus();
                return;
            }
            if (e.target.closest('[data-dtp-cancel]')) { close(modal); }
        });

        save.addEventListener('click', function () {
            if (name.value.trim() === '') { error.textContent = 'A template needs a name.'; return; }
            save.disabled = true;
            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ name: name.value, body: getBody(body) })
            }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
              .then(function (answer) {
                  save.disabled = false;
                  if (!answer.ok || !answer.data || !answer.data.id) {
                      error.textContent = 'That template was not saved. Check the name and try again.';
                      return;
                  }
                  // Both dropdowns learn it; the one that asked selects it.
                  Array.prototype.forEach.call(document.querySelectorAll('[data-dtp-select]'), function (select) {
                      var option = document.createElement('option');
                      option.value = String(answer.data.id);
                      option.textContent = answer.data.name;
                      select.appendChild(option);
                      if (asking && select === asking) select.value = String(answer.data.id);
                  });
                  close(modal);
              }, function () {
                  save.disabled = false;
                  error.textContent = 'Could not reach the server. Try again in a moment.';
              });
        });
    }

    function boot() { initPage(); initPick(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
