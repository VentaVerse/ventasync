var SVG_NS = 'http://www.w3.org/2000/svg';

function svgIcon(shapes) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'alert__icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');

    shapes.forEach(function (shape) {
        var el = document.createElementNS(SVG_NS, shape.tag);
        Object.keys(shape.attrs).forEach(function (name) {
            el.setAttribute(name, shape.attrs[name]);
        });
        svg.appendChild(el);
    });

    return svg;
}

function showFlash(tone, shapes, message) {
    var container = document.getElementById('js-flash-container');
    if (!container) return;

    var existing = container.querySelector('.alert');
    if (existing) existing.remove();

    var div = document.createElement('div');
    div.className = 'alert ' + tone;
    div.appendChild(svgIcon(shapes));

    var span = document.createElement('span');
    span.textContent = message;
    div.appendChild(span);

    var btn = document.createElement('button');
    btn.className = 'alert-close';
    btn.setAttribute('aria-label', 'Close');
    btn.textContent = '×';
    btn.addEventListener('click', function () { div.remove(); });
    div.appendChild(btn);

    container.appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

window.showFlashError = function (message) {
    showFlash('danger', [
        { tag: 'circle', attrs: { cx: '12', cy: '12', r: '10' } },
        { tag: 'line', attrs: { x1: '15', y1: '9', x2: '9', y2: '15' } },
        { tag: 'line', attrs: { x1: '9', y1: '9', x2: '15', y2: '15' } },
    ], message);
};

window.showFlashSuccess = function (message) {
    showFlash('success', [
        { tag: 'path', attrs: { d: 'M22 11.08V12a10 10 0 1 1-5.93-9.14' } },
        { tag: 'polyline', attrs: { points: '22 4 12 14.01 9 11.01' } },
    ], message);
};
