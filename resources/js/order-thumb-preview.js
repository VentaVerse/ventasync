/**
 * Hover preview for the product thumbnails on any fulfilment list.
 *
 * Lifted out of lazada-orders.js and shopee-orders.js, which carried two
 * copies of it. The storefront list carried neither, so once its rows gained
 * thumbnails they inherited `cursor: zoom-in` from the shared stylesheet and
 * then did nothing when you hovered: the cursor promised a zoom the page had
 * no code to perform. A behaviour that belongs to a shared component belongs
 * in one file, bound once.
 *
 * Delegated on document rather than bound per thumbnail, because these rows
 * are re-rendered on every filter, sort and page change.
 */
function initOrderThumbPreview() {
    // The panel can appear twice on one document (a hub page hosting a
    // channel), and one preview element is enough for the page.
    if (document.querySelector('.order-img-preview')) return;
    if (!document.querySelector('.order-img-wrap')) return;

    // An order DETAIL page opts out, and it is the one page that should.
    // Its thumbnails are already the 96px this preview would zoom them up
    // to, so hovering would swap an image for the same image. Each channel's
    // module used to make this decision separately by not calling its own
    // copy; stated once here, a new channel gets it without knowing.
    if (document.querySelector('.od-page')) return;

    var preview = document.createElement('div');
    preview.className = 'order-img-preview';

    var previewImg = document.createElement('img');
    previewImg.src = '';
    previewImg.alt = '';
    preview.appendChild(previewImg);
    document.body.appendChild(preview);

    function place(wrap) {
        var img = wrap.querySelector('img.order-img');
        if (!img || !img.src) return false;

        previewImg.src = img.src;

        var rect = wrap.getBoundingClientRect();
        var top = rect.top + rect.height / 2 - 110;
        var left = rect.right + 12;

        // Flip to the other side rather than hang off the viewport, and keep
        // the transform origin on the edge it grows from so it does not
        // appear to fly across the row.
        if (left + 230 > window.innerWidth) left = rect.left - 232;
        if (top + 220 > window.innerHeight) top = window.innerHeight - 224;
        if (top < 4) top = 4;

        preview.style.transformOrigin = (left < rect.left ? 'right' : 'left') + ' center';
        preview.style.top = top + 'px';
        preview.style.left = left + 'px';

        return true;
    }

    document.addEventListener('mouseover', function (e) {
        var wrap = e.target.closest ? e.target.closest('.order-img-wrap') : null;
        if (!wrap) return;
        if (place(wrap)) preview.classList.add('visible');
    }, false);

    document.addEventListener('mouseout', function (e) {
        var wrap = e.target.closest ? e.target.closest('.order-img-wrap') : null;
        if (!wrap) return;

        // Moving between the image and its own wrapper is not leaving it.
        if (e.relatedTarget && wrap.contains(e.relatedTarget)) return;

        preview.classList.remove('visible');
    }, false);

    // A thumbnail with no image has nothing to enlarge, so it should not
    // claim it does. The stylesheet sets zoom-in on every wrapper because it
    // cannot know which ones are empty; this can.
    document.querySelectorAll('.order-img-wrap').forEach(function (wrap) {
        if (!wrap.querySelector('img.order-img')) wrap.style.cursor = 'default';
    });
}

document.addEventListener('DOMContentLoaded', initOrderThumbPreview);
