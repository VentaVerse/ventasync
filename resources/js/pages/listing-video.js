(function () {
    'use strict';

    document.querySelectorAll('[data-listing-video]').forEach(setup);

    function setup(root) {
        const pathField = root.querySelector('[data-cv-path]');
        const offField = root.querySelector('[data-cv-off]');
        const input = root.querySelector('[data-cv-input]');
        const say = root.querySelector('[data-cv-say]');
        const productId = root.getAttribute('data-product') || '0';
        const uploadUrl = root.getAttribute('data-upload');

        function tell(message) {
            if (!say) return;
            say.textContent = message || '';
            say.hidden = !message;
        }

        function settled(message) {
            tell(message);
        }

        const drop = root.querySelector('[data-cv-drop]');
        if (drop) {
            drop.addEventListener('click', function () {
                offField.value = '1';
                pathField.value = '';
                settled('This store will send no video. Save to keep it.');
            });
        }

        const send = root.querySelector('[data-cv-send]');
        if (send) {
            send.addEventListener('click', function () {
                offField.value = '0';
                settled('This store will send the catalog\'s video. Save to keep it.');
            });
        }

        const revert = root.querySelector('[data-cv-revert]');
        if (revert) {
            revert.addEventListener('click', function () {
                pathField.value = '';
                offField.value = '0';
                settled('This store will follow the catalog again. Save to keep it.');
            });
        }

        if (!input) return;

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];
            if (!file) return;

            if (!/\.(mp4|mov)$/i.test(file.name)) {
                input.value = '';
                tell('That file is not a video this takes. Choose an MP4 or a MOV.');

                return;
            }

            tell('Uploading ' + file.name);

            const body = new FormData();
            body.append('video', file);
            body.append('product_id', productId);

            fetch(uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body,
            })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                .then(function (answer) {
                    input.value = '';
                    if (!answer.ok || !answer.data || !answer.data.video) {
                        tell((answer.data && answer.data.message) || 'The video could not be uploaded. Try again.');

                        return;
                    }
                    pathField.value = answer.data.video.path;
                    offField.value = '0';

                    settled('This store will send ' + answer.data.video.original_name + '. Save to keep it.');
                })
                .catch(function () {
                    input.value = '';
                    tell('The video could not be uploaded. Try again.');
                });
        });
    }
})();
