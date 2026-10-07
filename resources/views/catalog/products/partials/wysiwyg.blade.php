@once
@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css"
      integrity="sha384-vmPR5F5DxvnVZxuw9+hxaSj8MDX3rP49GZu/JvPS1qYD2xeg+0TGJUJ/H6e/HTkV"
      crossorigin="anonymous" referrerpolicy="no-referrer">
@endpush

@push('scripts')
<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"
        integrity="sha384-fq3mhgSZ+13XGKx7olcZUFWes9hDmAR3b/WnNLKH6fRFsHonf6CGG+Dj1wypCgLq"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.summernote) return;

    var pickPictures = null;
    if (document.querySelector('[data-ilp-modal]') && window.ImageLibrary) {
        pickPictures = function (done) { window.ImageLibrary.open(done); };
    } else if (typeof window.pimPickOne === 'function') {
        pickPictures = function (done) {
            window.pimPickOne(function (file) { done(file ? [file] : []); });
        };
    }

    function pictureSrc(file) {
        var src = file && typeof file.url === 'string' ? file.url.trim() : '';
        return /^https?:\/\//i.test(src) || /^\/(?!\/)/.test(src) ? src : '';
    }

    function libraryButton(context) {
        var ui = jQuery.summernote.ui;
        var icons = context.options.icons || {};
        var $button = ui.button({
            contents: ui.icon(icons.picture || 'note-icon-picture'),
            className: 'note-btn-library',
            click: function () {
                var editable = context.layoutInfo.editable[0];
                var saved = context.invoke('editor.getLastRange');
                if (!saved || !editable.contains(saved.sc)) {
                    var range = jQuery.summernote.range;
                    saved = range && range.createFromBodyElement
                        ? range.createFromBodyElement(editable, false)
                        : null;
                }

                pickPictures(function (files) {
                    var srcs = (files || []).map(pictureSrc).filter(Boolean);
                    if (!srcs.length) return;
                    if (saved) context.invoke('editor.setLastRange', saved);
                    context.invoke('editor.restoreRange');
                    srcs.forEach(function (src) {
                        var img = document.createElement('img');
                        img.setAttribute('src', src);
                        img.setAttribute('alt', '');
                        context.invoke('editor.insertNode', img);
                    });
                });
            }
        }).render();
        $button.attr('aria-label', 'Insert picture from the media library');
        return $button;
    }

    jQuery('textarea.wysiwyg').each(function () {
    var textarea = this;
    jQuery(textarea).summernote({
        height: 260,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', pickPictures ? ['link', 'table', 'library'] : ['link', 'table']],
            ['view', ['codeview']]
        ],
        buttons: pickPictures ? { library: libraryButton } : {},
        callbacks: {
            onInit: function () {
                const $note = jQuery(this);
                const editable = $note.next('.note-editor').find('.note-editable')[0];
                if (!editable) return;

                const textarea = $note[0];
                const label = textarea.id
                    ? document.querySelector('label[for="' + CSS.escape(textarea.id) + '"]')
                    : null;

                editable.setAttribute('aria-label',
                    (label && label.textContent.trim()) || 'Product description');
            },
            onChange: function (contents) {
                textarea.value = contents;
                if (textarea.id) {
                    document.querySelectorAll('[data-rte-edited="' + CSS.escape(textarea.id) + '"]')
                        .forEach(function (flag) { flag.value = '1'; });
                }
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });
    if (textarea.disabled) jQuery(textarea).summernote('disable');
    });
});
</script>
@endpush
@endonce
