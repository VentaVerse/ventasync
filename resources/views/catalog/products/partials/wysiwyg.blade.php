{{--
    Rich text for the product description.

    layouts.app loads jQuery + Summernote in its <head> for every legacy page
    and initialises `textarea.wysiwyg` there. The catalog forms are on
    layouts.shell now, which loads neither, so moving them without this partial
    would have quietly turned the description editor into a plain textarea -
    a capability loss, not a presentation change, and the reskin protocol does
    not allow those.

    So the exact same version, the exact same selector and the exact same
    toolbar configuration are pushed from here, on the two pages that need
    them, instead of being loaded app-wide. `resources/css/components/
    form-page.css` re-faces the editor chrome in design tokens so it does not
    read as a borrowed Bootstrap widget on a page that is not one.

    Known and deliberate: this is a CDN dependency on an editor that is no
    longer actively developed. Replacing it is its own task, not this one -
    doing it here would mean changing what gets stored in
    product_description.description while also changing every screen around
    it.
--}}
@once
{{-- Subresource integrity on all three, which the layouts.app copy does not
     carry: a CDN that starts serving something else gets ignored rather than
     executed. Hashes are sha384 of the pinned 0.8.20 / 3.7.1 bytes. --}}
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

    /* The media library, through whichever picker this page already has:
       the shared one (several pictures at once) where its modal is on the
       page, else the product form's own (one at a time). A page with
       neither gets no button. Each hands back [{url}]. */
    var pickPictures = null;
    if (document.querySelector('[data-ilp-modal]') && window.ImageLibrary) {
        pickPictures = function (done) { window.ImageLibrary.open(done); };
    } else if (typeof window.pimPickOne === 'function') {
        pickPictures = function (done) {
            window.pimPickOne(function (file) { done(file ? [file] : []); });
        };
    }

    /* Only a URL of this site or an http(s) one goes into an img src. */
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
                // A range left from content that has since been replaced
                // points at nothing: fall back to the end of the text.
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
                        // A command: it fires onChange, which marks the field edited.
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
            /* The editor Summernote builds is a role="textbox" div with no
               accessible name: the real <textarea name="description"> is
               correctly labelled and then hidden, so the label never reaches
               the control a person actually types in. It was announced as an
               unnamed edit area on both product forms.

               Named from the hidden textarea's own label where there is one,
               so the two never drift apart, with the field's purpose as the
               fallback. */
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
            /* Keep the textarea in sync, notify unsaved-change guards, and set
               the matching data-rte-edited flag so the server can tell a real
               edit from editor normalisation (see
               ListingContent::descriptionToSave). */
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
    // Read-only when the textarea is disabled.
    if (textarea.disabled) jQuery(textarea).summernote('disable');
    });
});
</script>
@endpush
@endonce
