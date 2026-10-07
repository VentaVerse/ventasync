const SVG_NS = 'http://www.w3.org/2000/svg';

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

function checkIcon() {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '14');
    svg.setAttribute('height', '14');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', 'M20 6 9 17l-5-5');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '2.5');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);
    return svg;
}

function inBadge(label) {
    const span = el('span', 'cc-addpanel__on');
    span.appendChild(checkIcon());
    span.appendChild(document.createTextNode(' ' + label));
    return span;
}

export function initAddPanel() {
    const panel = document.querySelector('[data-add-panel]');
    if (!panel) return;

    const searchUrl = panel.dataset.searchUrl;
    const addUrlTemplate = panel.dataset.addUrl;
    const inLabel = panel.dataset.inLabel || 'On this store';
    const scope = panel.dataset.scope || 'this store';
    const emptyDefault = panel.dataset.emptyDefault || 'Everything is already on ' + scope + '.';
    const emptyAll = panel.dataset.emptyAll || 'Nothing to show.';
    const input = panel.querySelector('[data-add-panel-search]');
    const showAll = panel.querySelector('[data-add-panel-show-all]');
    const list = panel.querySelector('[data-add-panel-list]');
    const meta = panel.querySelector('[data-add-panel-meta]');
    const addedEl = panel.querySelector('[data-add-panel-added]');
    const groupPick = panel.querySelector('[data-add-panel-group]');
    const tokenInput = panel.querySelector('input[name="_token"]');
    const token = tokenInput ? tokenInput.value : '';

    let added = 0;
    let timer = null;
    let requestSeq = 0;
    let lastTrigger = null;

    function setMeta(text) { meta.textContent = text; }

    function open(trigger) {
        lastTrigger = trigger || document.activeElement;
        added = 0;
        addedEl.textContent = '';
        input.value = '';
        if (showAll) showAll.checked = false;
        panel.classList.add('active');
        document.body.classList.add('cc-addpanel-open');
        search();
        setTimeout(function () { input.focus(); }, 60);
    }

    function close() {
        panel.classList.remove('active');
        document.body.classList.remove('cc-addpanel-open');
        if (added > 0) {
            window.location.reload();
            return;
        }
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    }

    function row(item) {
        const li = el('li', 'cc-addpanel__row' + (item.on_store ? ' is-on' : ''));

        const thumb = el('span', 'cc-addpanel__thumb');
        if (item.thumb) {
            const img = document.createElement('img');
            img.src = item.thumb;
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';
            thumb.appendChild(img);
        }
        li.appendChild(thumb);

        const body = el('span', 'cc-addpanel__body');
        body.appendChild(el('span', 'cc-addpanel__name', item.name));
        const bits = [];
        if (item.sku) bits.push(item.sku);
        bits.push(item.quantity + ' in stock');
        if (item.price) bits.push(item.price);
        if (item.elsewhere) bits.push('In ' + item.elsewhere);
        if (item.listed === false) bits.push('Not on this store yet');
        body.appendChild(el('span', 'cc-addpanel__meta2', bits.join(' · ')));
        li.appendChild(body);

        if (item.on_store) {
            li.appendChild(inBadge(inLabel));
        } else if (item.elsewhere) {
            const btn = el('button', 'cc-addpanel__add cc-addpanel__add--move', 'Move here');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Move ' + item.name + ' here from ' + item.elsewhere);
            btn.addEventListener('click', function () { add(item, btn, li, true); });
            li.appendChild(btn);
        } else {
            const btn = el('button', 'cc-addpanel__add', 'Add');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Add ' + item.name + ' to ' + scope);
            btn.addEventListener('click', function () { add(item, btn, li, false); });
            li.appendChild(btn);
        }
        return li;
    }

    function render(data) {
        list.replaceChildren();
        if (!data.items || data.items.length === 0) {
            const q = input.value.trim();
            list.appendChild(el('li', 'cc-addpanel__empty',
                q !== ''
                    ? 'Nothing matches. Try another name, model or SKU.'
                    : (showAll && showAll.checked ? emptyAll : emptyDefault)));
            setMeta('');
            return;
        }
        data.items.forEach(function (item) { list.appendChild(row(item)); });
        if (data.total > data.shown) {
            setMeta('Showing ' + data.shown + ' of ' + data.total + '. Search to narrow.');
        } else {
            setMeta(data.total + (data.total === 1 ? ' product' : ' products'));
        }
    }

    async function search() {
        const seq = ++requestSeq;
        const url = new URL(searchUrl, window.location.origin);
        const q = input.value.trim();
        if (q !== '') url.searchParams.set('q', q);
        if (showAll && showAll.checked) url.searchParams.set('all', '1');
        setMeta('Searching…');
        try {
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            if (seq !== requestSeq) return;
            render(data);
        } catch (err) {
            if (seq !== requestSeq) return;
            setMeta('Could not load the products. Try again.');
        }
    }

    async function add(item, btn, li, move) {
        const idle = btn.textContent;
        btn.disabled = true;
        btn.textContent = move ? 'Moving…' : 'Adding…';
        try {
            const res = await fetch(addUrlTemplate.replace('__PID__', String(item.id)), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(Object.assign(move ? { move: true } : {}, groupPick && groupPick.value ? { group: groupPick.value } : {})),
            });
            if (!res.ok) throw new Error(String(res.status));
            added += 1;
            addedEl.textContent = added === 1 ? '1 added this session' : added + ' added this session';
            li.classList.add('is-on');
            btn.replaceWith(inBadge(move ? 'Moved' : 'Added'));
        } catch (err) {
            btn.disabled = false;
            btn.textContent = idle;
            setMeta('Could not add ' + item.name + '. Try again.');
        }
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 250);
    });
    if (showAll) showAll.addEventListener('change', search);

    document.querySelectorAll('[data-add-panel-open]').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn); });
    });
    panel.querySelectorAll('[data-add-panel-close], [data-add-panel-done]').forEach(function (btn) {
        btn.addEventListener('click', close);
    });
    panel.addEventListener('click', function (e) { if (e.target === panel) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('active')) close();
    });
}

document.addEventListener('DOMContentLoaded', initAddPanel);
