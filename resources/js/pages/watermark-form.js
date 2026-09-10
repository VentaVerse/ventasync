/* THE WATERMARK TEMPLATE FORM (VENT2-128).
 *
 * Two jobs, both about seeing the thing before committing to it: pick the
 * mark out of the image library, and show what a marked photograph
 * actually looks like as the settings change.
 *
 * The preview is drawn by the SERVER, by the same stamper the push uses
 * (watermarks.preview), rather than approximated in CSS. A canvas
 * impression of a watermark is exactly the kind of preview that agrees
 * with the form and disagrees with the marketplace.
 */
(function () {
    function init() {
        var form = document.getElementById('wm-form');
        if (!form) return;

        var pick = form.querySelector('[data-wm-pick]');
        var pathEl = form.querySelector('[data-wm-path]');
        var markEl = form.querySelector('[data-wm-mark]');
        var browseBtn = form.querySelector('[data-wm-browse]');
        var preview = form.querySelector('[data-wm-preview]');
        var note = form.querySelector('[data-wm-preview-note]');
        var previewUrl = form.getAttribute('data-wm-preview-url');
        if (!pick || !pathEl) return;

        if (browseBtn && window.ImageLibrary) {
            browseBtn.addEventListener('click', function () {
                window.ImageLibrary.open(function (files) {
                    if (!files.length) return;
                    pathEl.value = files[0].path;
                    markEl.innerHTML = '';
                    var img = document.createElement('img');
                    img.src = files[0].url;
                    img.alt = '';
                    markEl.appendChild(img);
                    draw();
                }, { single: true });
            });
        }

        var timer = null;
        function draw() {
            if (!preview || !previewUrl || !pathEl.value) return;
            window.clearTimeout(timer);
            /* Debounced: the size and strength fields are spinners, and a
               request per keystroke would ask the server to stamp a photo
               twenty times while somebody holds an arrow key down. */
            timer = window.setTimeout(function () {
                var url = new URL(previewUrl, window.location.origin);
                url.searchParams.set('image_path', pathEl.value);
                ['position', 'size_percent', 'margin_percent', 'opacity'].forEach(function (name) {
                    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
                    if (el && el.value !== '') url.searchParams.set(name, el.value);
                });
                url.searchParams.set('_', String(Date.now()));

                preview.onload = function () {
                    preview.hidden = false;
                    if (note) note.hidden = true;
                };
                preview.onerror = function () {
                    preview.hidden = true;
                    if (note) {
                        note.hidden = false;
                        note.textContent = 'No preview yet: pick a mark, and have at least one product picture in the catalog.';
                    }
                };
                preview.src = url.toString();
            }, 220);
        }

        form.addEventListener('input', draw);
        form.addEventListener('change', draw);
        draw();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
