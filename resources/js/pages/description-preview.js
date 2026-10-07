(function () {
    // Must match the allowlist in App\Support\HtmlSanitizer; never assign a body as markup.
    var ALLOWED = {
        p: [], h1: [], h2: [], h3: [], h4: [], h5: [], h6: [], blockquote: [], pre: [],
        b: [], strong: [], i: [], em: [], u: [], br: [],
        ul: [], ol: [], li: [],
        a: ['href'],
        table: [], thead: [], tbody: [], tr: [], td: [], th: [],
        img: ['src', 'alt']
    };

    var DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'noscript', 'noframes', 'form', 'button', 'select',
        'option', 'optgroup', 'textarea', 'svg', 'math', 'template', 'link',
        'meta', 'base', 'audio', 'video', 'source', 'track', 'canvas'
    ];

    var DATA_IMAGE = /^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+=*$/;

    function safeUrl(value, isImage) {
        var trimmed = String(value).replace(/[\x00-\x1F\x7F]/g, '').replace(/^\s+/, '');
        if (trimmed === '' || trimmed.indexOf('//') === 0) return false;
        var scheme = /^([a-zA-Z][a-zA-Z0-9+.\-]*):/.exec(trimmed);
        if (!scheme) return true;
        var name = scheme[1].toLowerCase();
        if (isImage && name === 'data') return DATA_IMAGE.test(trimmed);
        return name === 'http' || name === 'https';
    }

    function copyClean(source, target) {
        Array.prototype.forEach.call(source.childNodes, function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                target.appendChild(document.createTextNode(node.nodeValue));
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            var tag = node.nodeName.toLowerCase();
            if (DROP.indexOf(tag) !== -1) return;
            if (!Object.prototype.hasOwnProperty.call(ALLOWED, tag)) {
                copyClean(node, target);
                return;
            }

            var el = document.createElement(tag);
            ALLOWED[tag].forEach(function (attr) {
                if (!node.hasAttribute(attr)) return;
                var value = node.getAttribute(attr);
                if ((attr === 'href' || attr === 'src') && !safeUrl(value, attr === 'src')) return;
                el.setAttribute(attr, value);
            });
            copyClean(node, el);
            target.appendChild(el);
        });
    }

    function boxes() {
        return document.querySelectorAll('[data-dp-box]');
    }

    function previewOf(box, id) {
        if (!id) return null;
        return box.querySelector(':scope > template[data-dt-preview="' + CSS.escape(String(id)) + '"]');
    }

    function paint(box, which) {
        var block = box.querySelector(':scope > [data-dp-block="' + which + '"]');
        var select = document.getElementById(box.getAttribute('data-dp-' + which + '-for') || '');
        if (!block || !select) return;

        var preview = previewOf(box, select.value);
        if (!preview) {
            block.hidden = true;
            block.replaceChildren();
            return;
        }
        block.replaceChildren(preview.content.cloneNode(true));
        block.hidden = false;
    }

    function paintAll() {
        Array.prototype.forEach.call(boxes(), function (box) {
            paint(box, 'prefix');
            paint(box, 'suffix');
        });
    }

    function learn(box, id, body) {
        if (previewOf(box, id)) return;
        var text = typeof body === 'string' ? body : '';
        if (text.trim() === '') return;

        var preview = document.createElement('template');
        preview.setAttribute('data-dt-preview', String(id));

        if (box.getAttribute('data-dp-kind') === 'html') {
            var parsed = new DOMParser().parseFromString(text, 'text/html');
            var fragment = document.createDocumentFragment();
            copyClean(parsed.body, fragment);
            if (fragment.textContent.trim() === '' && !fragment.querySelector('img')) return;
            preview.content.appendChild(fragment);
        } else {
            preview.content.appendChild(document.createTextNode(text.trim()));
        }
        box.appendChild(preview);
    }

    function boot() {
        if (!boxes().length) return;

        document.addEventListener('change', function (e) {
            if (e.target && e.target.matches && e.target.matches('[data-dtp-select]')) paintAll();
        });

        document.addEventListener('dtp:created', function (e) {
            var detail = e.detail || {};
            if (!detail.id) return;
            Array.prototype.forEach.call(boxes(), function (box) { learn(box, detail.id, detail.body); });
            paintAll();
        });

        paintAll();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
