(function () {
    var MINUS = '−';

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

        function readout(range) {
            var out = range.parentNode.querySelector('[data-wm-readout]');
            if (!out) return;
            var v = parseFloat(range.value);
            var unit = range.getAttribute('data-wm-unit') || '';
            var text = (Math.round(v * 10) / 10).toString();
            if (range.hasAttribute('data-wm-signed')) {
                if (v > 0) text = '+' + text;
                else if (v < 0) text = MINUS + text.slice(1);
            }
            out.textContent = text + unit;
            var min = parseFloat(range.min);
            var max = parseFloat(range.max);
            var at = (v - min) / (max - min) * 100;
            if (range.hasAttribute('data-wm-signed')) {
                range.style.setProperty('--wm-from', Math.min(50, at) + '%');
                range.style.setProperty('--wm-to', Math.max(50, at) + '%');
            } else {
                range.style.setProperty('--wm-from', '0%');
                range.style.setProperty('--wm-to', at + '%');
            }
        }

        var ranges = Array.prototype.slice.call(form.querySelectorAll('.wm-slider__range'));
        ranges.forEach(function (range) {
            readout(range);
            range.addEventListener('input', function () { readout(range); });
            if (range.hasAttribute('data-wm-signed')) {
                range.addEventListener('dblclick', function () {
                    range.value = '0';
                    readout(range);
                    range.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }
        });

        function value(name) {
            var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        }

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
                    pathEl.dispatchEvent(new Event('change', { bubbles: true }));
                    draw();
                }, { single: true });
            });
        }

        var timer = null;
        function draw() {
            if (!preview || !previewUrl || !pathEl.value) return;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                var url = new URL(previewUrl, window.location.origin);
                url.searchParams.set('image_path', pathEl.value);
                ['position', 'size_percent', 'offset_x_percent', 'offset_y_percent', 'transparency'].forEach(function (name) {
                    var v = value(name);
                    if (v !== '') url.searchParams.set(name, v);
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
