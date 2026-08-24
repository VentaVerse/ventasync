/**
 * Hide a catalog thumbnail that fails to load, revealing the placeholder
 * underneath it.
 *
 * Product images are referenced by a path this installation may not hold:
 * a self-hoster can serve them from a separate storefront (CATALOG_PUBLIC_URL),
 * and a fresh install has none of them at all. Without this, every row in a
 * 2,000 product list renders the browser's broken-image glyph, which reads as
 * "this application is broken" rather than "this product has no picture yet".
 *
 * The listener is registered in the CAPTURE phase because `error` events on
 * media elements do not bubble, so a delegated listener on document would
 * never see them otherwise. One listener covers every image on the page and
 * every image added to it later.
 */
document.addEventListener(
    'error',
    (event) => {
        const el = event.target;

        if (!(el instanceof HTMLImageElement)) {
            return;
        }

        if (el.hasAttribute('data-thumb')) {
            el.remove();
            return;
        }

        // The image manager builds its thumbnails in JavaScript and its drag
        // and drop logic owns that DOM, so a missing file is hidden rather
        // than removed: the tile keeps its place in the grid and the sort
        // order it carries stays intact.
        if (el.classList.contains('fm-img__thumb')) {
            el.style.visibility = 'hidden';
        }
    },
    true
);

/**
 * The listener above cannot catch what already happened. app.js is a module
 * script, so it is deferred until after parsing, by which time the first
 * screenful of thumbnails has usually already failed and fired its error
 * event into a document that was not listening yet. A finished image that
 * decoded to zero width is a failed one, so sweep those on load.
 */
function sweepBrokenThumbs() {
    document.querySelectorAll('img[data-thumb], img.fm-img__thumb').forEach((img) => {
        if (!img.complete || img.naturalWidth !== 0) {
            return;
        }

        if (img.hasAttribute('data-thumb')) {
            img.remove();
        } else {
            img.style.visibility = 'hidden';
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sweepBrokenThumbs);
} else {
    sweepBrokenThumbs();
}

// Lazy-loaded rows further down the page finish after DOMContentLoaded, so
// one more pass once every pending image has settled.
window.addEventListener('load', sweepBrokenThumbs);
