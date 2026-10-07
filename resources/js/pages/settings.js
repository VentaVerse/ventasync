(function () {
    'use strict';

    function textOf(el) {
        if (!el) return '';
        return 'value' in el && el.value !== undefined && el.tagName !== 'CODE'
            ? String(el.value)
            : String(el.textContent || '');
    }

    async function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (e) {
            }
        }
        const scratch = document.createElement('textarea');
        scratch.value = text;
        scratch.setAttribute('readonly', 'readonly');
        scratch.setAttribute('aria-hidden', 'true');
        scratch.classList.add('x-sr');
        document.body.appendChild(scratch);
        scratch.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(scratch);
        return ok;
    }

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('[data-copy-target]');
        if (!btn) return;

        ev.preventDefault();

        const source = document.getElementById(btn.dataset.copyTarget);
        if (!source) return;

        const original = btn.dataset.copyLabel || btn.textContent.trim();
        btn.dataset.copyLabel = original;

        copy(textOf(source)).then(function (ok) {
            btn.textContent = ok ? 'Copied' : 'Press Ctrl C';
            window.setTimeout(function () {
                btn.textContent = btn.dataset.copyLabel;
            }, 1600);
        });
    });

    function applyToggle(box) {
        const field = document.getElementById(box.dataset.toggleDisables);
        if (!field) return;
        field.disabled = box.checked;
    }

    const toggles = document.querySelectorAll('[data-toggle-disables]');
    toggles.forEach(function (box) {
        applyToggle(box);
        box.addEventListener('change', function () {
            applyToggle(box);
        });
    });

    const tokenModal = document.querySelector('[data-token-modal]');
    if (tokenModal) {
        const tokenTitle = tokenModal.querySelector('[data-token-title]');
        const tokenStatus = tokenModal.querySelector('[data-token-status]');
        const tokenRow = tokenModal.querySelector('[data-token-row]');
        const tokenField = tokenModal.querySelector('#st-token-value');
        const csrf = document.querySelector('meta[name="csrf-token"]');
        let tokenTrigger = null;
        let tokenRequest = 0;

        const say = function (text) {
            tokenStatus.textContent = text;
            tokenStatus.hidden = text === '';
        };

        const openTokenModal = function () {
            tokenModal.classList.add('active');
            (tokenRow.hidden ? tokenModal.querySelector('.modal-close') : tokenField).focus();
        };

        const closeTokenModal = function () {
            tokenRequest++;
            tokenModal.classList.remove('active');
            tokenField.value = '';
            tokenRow.hidden = true;
            say('');
            if (tokenTrigger && document.contains(tokenTrigger)) tokenTrigger.focus();
            tokenTrigger = null;
        };

        document.addEventListener('click', function (ev) {
            const trigger = ev.target.closest('[data-token-view]');
            if (!trigger) return;
            ev.preventDefault();

            tokenTrigger = trigger;
            const request = ++tokenRequest;
            tokenTitle.textContent = 'Token for ' + (trigger.dataset.tokenName || 'this application');
            tokenField.value = '';
            tokenRow.hidden = true;
            say('Loading the token.');
            openTokenModal();

            fetch(trigger.dataset.tokenView, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.content : '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        return { ok: res.ok, status: res.status, body: body };
                    });
                })
                .then(function (answer) {
                    if (request !== tokenRequest) return;
                    if (answer.ok && typeof answer.body.token === 'string') {
                        tokenField.value = answer.body.token;
                        say('');
                        tokenRow.hidden = false;
                        tokenField.focus();
                        return;
                    }
                    if (answer.status === 404 && answer.body.message) say(answer.body.message);
                    else if (answer.status === 419) say('Your session has expired. Reload the page and try again.');
                    else say('The token could not be loaded.');
                })
                .catch(function () {
                    if (request === tokenRequest) say('The token could not be loaded.');
                });
        });

        tokenModal.querySelectorAll('[data-token-close]').forEach(function (btn) {
            btn.addEventListener('click', closeTokenModal);
        });

        tokenModal.addEventListener('click', function (ev) {
            if (ev.target === tokenModal) closeTokenModal();
        });

        document.addEventListener('keydown', function (ev) {
            if (!tokenModal.classList.contains('active')) return;
            if (ev.key === 'Escape') {
                ev.preventDefault();
                closeTokenModal();
                return;
            }
            if (ev.key !== 'Tab') return;
            const focusable = Array.prototype.filter.call(
                tokenModal.querySelectorAll('button, input'),
                function (el) { return !el.disabled && el.offsetParent !== null; }
            );
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (ev.shiftKey && document.activeElement === first) {
                ev.preventDefault();
                last.focus();
            } else if (!ev.shiftKey && document.activeElement === last) {
                ev.preventDefault();
                first.focus();
            }
        });

        if (tokenModal.hasAttribute('data-token-open')) openTokenModal();
    }

    document.querySelectorAll('[data-scope-matrix]').forEach(function (matrix) {
        matrix.querySelectorAll('[data-scope-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                const want = button.dataset.scopeAll;
                matrix.querySelectorAll('.st-level').forEach(function (level) {
                    const pick = level.querySelector('.st-level__input[value="' + want + '"]')
                        || level.querySelector('.st-level__input[value="read"]');
                    if (pick) pick.checked = true;
                });
            });
        });
    });

    document.querySelectorAll('[data-select-on-focus]').forEach(function (field) {
        field.addEventListener('focus', function () {
            field.select();
        });
    });

    document.querySelectorAll('.st-file__input').forEach(function (input) {
        const readout = document.querySelector('[data-file-name-for="' + input.id + '"]');
        const errorLine = document.getElementById(input.id + '-error');
        const reset = document.querySelector('[data-file-reset="' + input.id + '"]');
        const preview = input.dataset.imagePreview ? document.getElementById(input.dataset.imagePreview) : null;
        const box = preview ? preview.closest('.st-brandnow') : null;
        const saved = { src: preview ? preview.getAttribute('src') : '', caption: '', shown: box ? !box.hidden : false };
        const captionEl = preview && preview.parentNode ? preview.parentNode.querySelector('.st-brand__cap') : null;
        if (captionEl) saved.caption = captionEl.textContent;

        function say(message) {
            if (!errorLine) return;
            errorLine.textContent = message || '';
            errorLine.hidden = !message;
        }

        function restore() {
            input.value = '';
            if (readout) readout.textContent = 'No file chosen';
            if (reset) reset.hidden = true;
            if (preview) preview.setAttribute('src', saved.src);
            if (captionEl) captionEl.textContent = saved.caption;
            if (box) box.hidden = !saved.shown;
        }

        if (reset) {
            reset.addEventListener('click', function () {
                restore();
                say('');
            });
        }

        function refuse(file) {
            const maxKb = parseInt(input.dataset.maxKb || '0', 10);
            if (maxKb && file.size > maxKb * 1024) {
                const mb = (file.size / 1048576).toFixed(1);
                const limit = maxKb >= 1024 ? (maxKb / 1024) + ' MB' : maxKb + ' KB';
                return 'That file is ' + mb + ' MB. Choose one up to ' + limit + '.';
            }
            const accepted = (input.getAttribute('accept') || '').split(',').map(function (a) { return a.trim().toLowerCase(); }).filter(Boolean);
            if (accepted.length) {
                const type = (file.type || '').toLowerCase();
                const name = file.name.toLowerCase();
                const ok = accepted.some(function (a) {
                    return a.charAt(0) === '.' ? name.endsWith(a) : type === a;
                });
                if (!ok) {
                    return 'That file is not a picture this takes. Choose ' + (input.dataset.kinds || 'a picture') + '.';
                }
            }

            return '';
        }

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];

            if (file) {
                const problem = refuse(file);
                if (problem) {
                    restore();
                    say(problem);
                    return;
                }
            }
            say('');
            if (reset) reset.hidden = !file;

            if (readout) {
                readout.textContent = file ? file.name : 'No file chosen';
                readout.title = file ? file.name : '';
            }

            const targetId = input.dataset.imagePreview;
            if (!targetId || !file) return;

            const img = document.getElementById(targetId);
            if (!img) return;

            const reader = new FileReader();
            reader.onload = function (ev) {
                img.src = ev.target.result;
                const box = img.closest('.st-brandnow');
                if (box) box.hidden = false;
                const caption = img.parentNode
                    ? img.parentNode.querySelector('.st-brand__cap')
                    : null;
                if (caption) caption.textContent = 'Not saved yet';
            };
            reader.readAsDataURL(file);
        });
    });

    document.querySelectorAll('[data-smtp-toggle]').forEach(function (select) {
        const block = document.getElementById(select.dataset.smtpToggle);
        if (!block) return;

        function apply() {
            block.hidden = select.value !== 'smtp';
        }

        select.addEventListener('change', apply);
        apply();
    });

    document.querySelectorAll('[data-test-mail]').forEach(function (button) {
        const input = document.getElementById(button.dataset.testInput);
        const result = document.getElementById(button.dataset.testResult);
        if (!input || !result) return;

        const token = document.querySelector('meta[name="csrf-token"]');

        button.addEventListener('click', function () {
            const to = (input.value || '').trim();

            result.hidden = false;
            result.className = 'st-test__result';

            if (!to) {
                result.classList.add('is-fail');
                result.textContent = 'Type an address to send to.';
                return;
            }

            const label = button.textContent;
            button.disabled = true;
            button.textContent = 'Sending';
            result.textContent = 'Sending to ' + to;

            fetch(button.dataset.testMail, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                },
                body: JSON.stringify({ to: to }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    result.classList.add(data.ok ? 'is-ok' : 'is-fail');
                    result.textContent = data.message || (data.ok ? 'Sent.' : 'It did not send.');
                })
                .catch(function () {
                    result.classList.add('is-fail');
                    result.textContent = 'The server did not answer. Check the connection and try again.';
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = label;
                });
        });
    });
})();
