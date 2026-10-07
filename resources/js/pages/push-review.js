(function () {
    'use strict';

    const scrim = document.querySelector('[data-pr-scrim]');
    const single = document.querySelector('[data-pr-single]');
    if (!scrim || !single) return;

    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function openModal(which) {
        scrim.hidden = false;
        if (single) single.hidden = which !== 'single';
    }

    function closeModal() {
        scrim.hidden = true;
        if (single) single.hidden = true;
    }

    scrim.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
    document.querySelectorAll('[data-pr-close]').forEach(function (b) { b.addEventListener('click', closeModal); });

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function getJson(url) {
        return fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }

    let singleForm = null;

    function fact(dl, key, value, muted) {
        const wrap = el('div', 'pr-fact');
        wrap.appendChild(el('dt', null, key));
        const dd = el('dd', null, value);
        if (muted) dd.appendChild(el('span', 'pr-mut', ' ' + muted));
        wrap.appendChild(dd);
        dl.appendChild(wrap);
    }

    function fillSingle(data) {
        single.querySelector('[data-pr-sub]').textContent = data.name + (data.sku ? ' · ' + data.sku : '');
        const facts = single.querySelector('[data-pr-facts]');
        facts.textContent = '';
        fact(facts, 'Goes up at', '₱' + data.price, '(₱' + data.core_price + ' catalog, ' + data.rule + ')');
        fact(facts, 'Category', data.category || 'None');
        fact(facts, 'Couriers', data.couriers.length ? data.couriers.join(', ') : 'None');
        fact(facts, 'Images', String(data.images) + ' from the catalog product', data.images_note || undefined);
        if (data.variations > 0) fact(facts, 'Variations', String(data.variations) + ' go with it');
        single.querySelector('[data-pr-title]').value = data.title || '';
        single.querySelector('[data-pr-description]').value = data.description || '';
        single.querySelector('[data-pr-listing-link]').setAttribute('href', data.listing_url);
    }

    single && single.querySelector('[data-pr-push]').addEventListener('click', function () {
        if (!singleForm) return;
        ['title', 'description'].forEach(function (name) {
            let input = singleForm.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                singleForm.appendChild(input);
            }
            input.value = single.querySelector('[data-pr-' + name + ']').value;
        });

        closeModal();
        document.dispatchEvent(new CustomEvent('busy:show', {
            detail: { title: 'Pushing to ' + (single.getAttribute('data-store') || 'the store') },
        }));

        singleForm.submit();
    });

    function interceptSingle(form) {
        singleForm = form;
        getJson(form.getAttribute('data-push-review')).then(function (data) {
            if (!data.ok) { form.submit(); return; }
            fillSingle(data);
            openModal('single');
        }).catch(function () { form.submit(); });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.hasAttribute('data-push-review')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            interceptSingle(form);
        }
    }, true);
})();
