function norm(v) {
    if (v === null || v === undefined) return '';
    const s = String(v).trim();
    if (s === '') return '';
    if (/^-?\d+(\.\d+)?$/.test(s)) return String(parseFloat(s));
    return s.toLowerCase();
}

function fire(el, types) {
    types.forEach(function (t) { el.dispatchEvent(new Event(t, { bubbles: true })); });
}

function initBand(band) {
    const form = band.closest('form') || document;
    const holder = band.querySelector('[data-group-values]');
    if (!holder) return;
    let data;
    try { data = JSON.parse(holder.textContent || '{}'); } catch (e) { return; }
    const fields = data.fields || {};
    const values = data.values || {};
    const urls = data.urls || {};

    const select = band.querySelector('[data-group-select]');
    const open = band.querySelector('[data-group-open]');
    const diff = band.querySelector('[data-group-diff]');
    const diffCount = band.querySelector('[data-group-diff-count]');
    const diffNames = band.querySelector('[data-group-diff-names]');
    const reset = band.querySelector('[data-group-reset]');
    const q = function (sel) { return sel ? form.querySelector(sel) : null; };

    function has(v) {
        if (v === null || v === undefined) return false;
        if (Array.isArray(v)) return v.length > 0;
        if (typeof v === 'object') return Object.keys(v).length > 0;
        return String(v).trim() !== '';
    }

    function attributeInput(key) {
        return form.querySelector('[name="attributes[' + CSS.escape(String(key)) + ']"]');
    }

    function attributeLabel(el, key) {
        const label = el && el.id ? form.querySelector('label[for="' + CSS.escape(el.id) + '"]') : null;
        return label ? label.textContent.trim() : String(key);
    }

    function differing(groupValues) {
        const names = [];
        Object.keys(fields).forEach(function (key) {
            const f = fields[key];
            const gv = groupValues[key];
            if (!has(gv) && f.kind !== 'follow') return;
            if (f.kind === 'input') {
                const el = q(f.el);
                if (el && norm(el.value) !== norm(gv)) names.push(f.label);
            } else if (f.kind === 'combo') {
                const el = q(f.value);
                if (el && norm(el.value) !== norm(gv && gv.id)) names.push(f.label);
            } else if (f.kind === 'checks') {
                const on = Array.from(form.querySelectorAll('input[name="' + f.name + '"]:checked')).map(function (c) { return norm(c.value); }).sort();
                const want = (gv || []).map(norm).sort();
                if (on.join(',') !== want.join(',')) names.push(f.label);
            } else if (f.kind === 'follow') {
                const el = q(f.el);
                if (el && el.value !== '' && norm(el.value) !== norm(gv)) names.push(f.label);
            } else if (f.kind === 'lzbrand') {
                const none = q(f.none);
                const id = q(f.id);
                const same = gv && gv.none ? (none && none.value === '1') : (id && norm(id.value) === norm(gv && gv.id));
                if (!same) names.push(f.label);
            } else if (f.kind === 'attributes') {
                Object.keys(gv || {}).forEach(function (k) {
                    const el = attributeInput(k);
                    if (el && norm(el.value) !== norm(gv[k])) names.push(attributeLabel(el, k));
                });
            }
        });
        return names;
    }

    function showDiff() {
        const groupValues = select && select.value ? values[select.value] : null;
        if (!diff || !groupValues) {
            if (diff) diff.hidden = true;
            return;
        }
        const names = differing(groupValues);
        diff.hidden = names.length === 0;
        if (names.length) {
            diffCount.textContent = names.length === 1 ? '1 field' : names.length + ' fields';
            diffNames.textContent = names.join(', ');
        }
    }

    function fillAttributes(gv) {
        Object.keys(gv || {}).forEach(function (k) {
            const el = attributeInput(k);
            if (el) { el.value = gv[k]; fire(el, ['input', 'change']); }
        });
    }

    function fillField(f, gv) {
        if (f.kind === 'input') {
            const el = q(f.el);
            if (el) { el.value = has(gv) ? gv : ''; fire(el, ['input', 'change']); }
        } else if (f.kind === 'combo') {
            const value = q(f.value);
            const text = q(f.text);
            if (!value) return false;
            const id = gv && gv.id !== undefined && gv.id !== null ? String(gv.id) : '';
            const changed = value.value !== id;
            value.value = id;
            if (text) {
                text.value = gv && gv.label ? gv.label : '';
                text.setCustomValidity('');
            }
            if (changed) fire(value, ['change']);
            return changed;
        } else if (f.kind === 'checks') {
            const want = (gv || []).map(String);
            form.querySelectorAll('input[name="' + f.name + '"]').forEach(function (c) {
                c.checked = want.indexOf(String(c.value)) !== -1;
            });
            const first = form.querySelector('input[name="' + f.name + '"]');
            if (first) fire(first, ['change']);
        } else if (f.kind === 'follow') {
            const el = q(f.el);
            if (el) { el.value = ''; fire(el, ['change']); }
        } else if (f.kind === 'lzbrand') {
            const id = q(f.id);
            const none = q(f.none);
            const text = q(f.text);
            const readout = q(f.readout);
            const isNone = !!(gv && gv.none);
            if (id) id.value = isNone || !gv ? '' : String(gv.id || '');
            if (none) none.value = isNone ? '1' : '0';
            if (text) { text.value = isNone ? 'No Brand' : (gv && gv.label ? gv.label : ''); text.setCustomValidity(''); }
            if (readout) readout.textContent = id && id.value ? id.value : 'None';
            if (id) fire(id, ['change']);
        }
        return false;
    }

    function fill(groupId) {
        const gv = values[groupId];
        if (!gv) return;
        const gives = function (key) { return fields[key].kind === 'follow' || has(gv[key]); };
        let reloading = false;
        Object.keys(fields).forEach(function (key) {
            const f = fields[key];
            if (f.kind === 'combo' && f.reloads && gives(key)) {
                reloading = fillField(f, gv[key]) || reloading;
            }
        });
        Object.keys(fields).forEach(function (key) {
            const f = fields[key];
            if ((f.kind === 'combo' && f.reloads) || f.kind === 'attributes' || !gives(key)) return;
            fillField(f, gv[key]);
        });
        const attrKey = Object.keys(fields).find(function (key) { return fields[key].kind === 'attributes'; });
        if (attrKey) {
            if (reloading) {
                document.addEventListener('attributes:loaded', function once() {
                    document.removeEventListener('attributes:loaded', once);
                    fillAttributes(gv[attrKey]);
                    showDiff();
                });
            } else {
                fillAttributes(gv[attrKey]);
            }
        }
        showDiff();
    }

    if (select) {
        select.addEventListener('change', function () {
            const url = select.value ? urls[select.value] : null;
            if (open) {
                open.hidden = !url;
                if (url) open.href = url;
            }
            if (select.value) fill(select.value);
            showDiff();
        });
    }
    if (reset) {
        reset.addEventListener('click', function () {
            if (select && select.value) fill(select.value);
        });
    }

    let timer = null;
    const later = function () { clearTimeout(timer); timer = setTimeout(showDiff, 120); };
    form.addEventListener('input', later);
    form.addEventListener('change', later);
    document.addEventListener('attributes:loaded', later);
    showDiff();
}

function boot() {
    document.querySelectorAll('[data-group-band]').forEach(initBand);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
