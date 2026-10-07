function initOrderThumbPreview() {
    if (document.querySelector('.order-img-preview')) return;
    if (!document.querySelector('.order-img-wrap')) return;

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

        if (e.relatedTarget && wrap.contains(e.relatedTarget)) return;

        preview.classList.remove('visible');
    }, false);

    document.querySelectorAll('.order-img-wrap').forEach(function (wrap) {
        if (!wrap.querySelector('img.order-img')) wrap.style.cursor = 'default';
    });
}

document.addEventListener('DOMContentLoaded', initOrderThumbPreview);
