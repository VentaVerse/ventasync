(function () {
    'use strict';

    const root = document.querySelector('[data-product-video]');
    if (!root) return;

    const input = root.querySelector('[data-pv-input]');
    const readout = root.querySelector('[data-pv-readout]');
    const remove = root.querySelector('[data-pv-remove]');
    const pathField = root.querySelector('[data-pv-path]');
    const nameField = root.querySelector('[data-pv-name]');
    const now = root.querySelector('[data-pv-now]');
    const player = root.querySelector('[data-pv-player]');
    const lengthEl = root.querySelector('[data-pv-length]');
    const sizeEl = root.querySelector('[data-pv-size]');
    const errorLine = document.getElementById('pv-error');
    const token = root.getAttribute('data-token') || '';
    const productId = root.getAttribute('data-product') || '0';

    function csrf() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function say(message) {
        if (!errorLine) return;
        errorLine.textContent = message || '';
        errorLine.hidden = !message;
    }

    function show(video) {
        pathField.value = video.path;
        nameField.value = video.original_name || video.name || '';
        readout.textContent = nameField.value || 'No file chosen';
        readout.title = nameField.value || '';
        if (player) player.src = video.url || '';
        if (lengthEl) lengthEl.textContent = video.length || 'Length unknown';
        if (sizeEl) sizeEl.textContent = video.size || '';
        if (now) now.hidden = false;
        if (remove) remove.hidden = false;
    }

    function clear() {
        pathField.value = '';
        nameField.value = '';
        input.value = '';
        readout.textContent = 'No file chosen';
        readout.title = '';
        if (player) player.removeAttribute('src');
        if (now) now.hidden = true;
        if (remove) remove.hidden = true;
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;

        const name = file.name.toLowerCase();
        if (!/\.(mp4|mov)$/.test(name)) {
            input.value = '';
            say('That file is not a video this takes. Choose an MP4 or a MOV.');
            return;
        }

        say('');
        readout.textContent = 'Uploading ' + file.name;

        const body = new FormData();
        body.append('video', file);
        body.append('product_id', productId);
        body.append('token', token);

        fetch(root.getAttribute('data-upload'), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (answer) {
                if (!answer.ok || !answer.data || !answer.data.video) {
                    input.value = '';
                    readout.textContent = pathField.value ? nameField.value : 'No file chosen';
                    const said = answer.data && answer.data.message ? answer.data.message : '';
                    say(said || 'The video could not be uploaded. Try again.');
                    return;
                }
                show(answer.data.video);
            })
            .catch(function () {
                input.value = '';
                readout.textContent = pathField.value ? nameField.value : 'No file chosen';
                say('The video could not be uploaded. Try again.');
            });
    });

    if (remove) {
        remove.addEventListener('click', function () {
            const path = pathField.value;
            if (!path) return;

            const body = new FormData();
            body.append('path', path);
            body.append('product_id', productId);

            fetch(root.getAttribute('data-remove'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            }).catch(function () { });

            clear();
            say('');
        });
    }
})();
