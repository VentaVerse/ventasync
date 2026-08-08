{{--
    Rich text for the product description.

    layouts.app loads jQuery + Summernote in its <head> for every legacy page
    and initialises `textarea.wysiwyg` there. The catalogue forms are on
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
    jQuery('textarea.wysiwyg').summernote({
        height: 320,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link', 'table']],
            ['view', ['codeview']]
        ]
    });
});
</script>
@endpush
@endonce
