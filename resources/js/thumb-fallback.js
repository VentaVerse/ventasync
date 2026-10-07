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

        if (el.classList.contains('fm-img__thumb')) {
            el.style.visibility = 'hidden';
        }
    },
    true
);

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

window.addEventListener('load', sweepBrokenThumbs);
