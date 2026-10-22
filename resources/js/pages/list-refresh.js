function rebuildCouriers(box, items) {
    const list = box.querySelector('[data-courier-list]');
    const row = box.querySelector('template[data-courier-row]');
    const empty = box.querySelector('[data-courier-empty]');
    if (!list || !row) return;
    const ticked = new Set(Array.from(list.querySelectorAll('input:checked')).map(function (i) { return i.value; }));
    list.replaceChildren();
    items.forEach(function (item) {
        const label = row.content.firstElementChild.cloneNode(true);
        const input = label.querySelector('input');
        input.value = String(item.logistic_id);
        input.checked = ticked.has(input.value);
        label.querySelector('.gf-check__name').textContent = item.logistic_name;
        label.querySelector('.gf-check__id').textContent = String(item.logistic_id);
        if (item.enabled) label.querySelector('.gf-check__off').remove();
        list.appendChild(label);
    });
    list.hidden = items.length === 0;
    if (empty) empty.hidden = items.length !== 0;
}

document.addEventListener('click', function (e) {
    const button = e.target.closest('[data-list-refresh]');
    if (!button) return;
    const scope = button.closest('[data-couriers]') || button.parentElement;
    const say = scope.querySelector('[data-list-refresh-say]');
    const restore = button.textContent;
    button.disabled = true;
    button.textContent = 'Refreshing';
    if (say) say.textContent = '';

    fetch(button.getAttribute('data-list-refresh'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (say) say.textContent = (data && data.message) || 'Could not refresh.';
            if (data && data.ok && Array.isArray(data.items)) rebuildCouriers(scope, data.items);
        })
        .catch(function () {
            if (say) say.textContent = 'Could not refresh.';
        })
        .finally(function () {
            button.disabled = false;
            button.textContent = restore;
        });
});
