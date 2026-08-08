/*
 * window.showFlashError / window.showFlashSuccess.
 *
 * Pages call these instead of alert(). They were originally defined only by an
 * inline <script> at the bottom of layouts/app.blade.php, so any page moved to
 * a different layout lost them and every call site threw a ReferenceError,
 * which also stranded whatever ran after the call (the Confirm Ship button on
 * Shopee's orders page stayed disabled forever for exactly that reason). This
 * module was written as a faithful copy of that inline original, deferred, so
 * it reassigned the same two names on pages using that layout - which was the
 * only way to share the implementation while the original file was protected
 * and could not be edited.
 *
 * layouts/app.blade.php was deleted in Round F Task 4. This is the only
 * definition of either function now, and nothing reassigns them.
 *
 * Both write into #js-flash-container, which partials/legacy-page-contract
 * renders (layouts.channel includes it). A page without that container is a
 * no-op rather than an error.
 *
 * Neither function reads a Blade-rendered value, which is why they can live in
 * a real JS file at all. Both build their DOM with createElement and
 * textContent - never innerHTML - so a message containing markup is shown as
 * text and cannot inject anything.
 */

var SVG_NS = 'http://www.w3.org/2000/svg';

/*
 * The icon's geometry used to be an inline style attribute copied off the old
 * layout ("width:18px;height:18px;flex-shrink:0;margin-top:1px"). It is the
 * .alert__icon class in resources/css/components/alerts.css, with those exact
 * values, and that is what the Blade copy of this markup in
 * partials/legacy-page-contract.blade.php has always used. Setting the class
 * here instead means the two flash paths - server-rendered and script-rendered
 * - are styled by one rule, and no CSS is written in a string. setAttribute
 * rather than className because on an SVG element className is a read-only
 * SVGAnimatedString.
 */
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

/*
 * One builder for both tones. The two functions differed only in the tone
 * class and the icon's shapes; everything else - the single-alert replacement,
 * the close button, the scroll into view - was duplicated verbatim.
 */
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
