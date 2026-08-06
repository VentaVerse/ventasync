/*
 * window.showFlashError / window.showFlashSuccess.
 *
 * Legacy pages call these instead of alert(). They were only ever defined by
 * an inline <script> at the bottom of layouts/app.blade.php, so any page moved
 * to a different layout lost them and every call site threw a ReferenceError,
 * which also stranded whatever ran after the call (the Confirm Ship button on
 * Shopee's orders page stayed disabled forever for exactly that reason).
 *
 * layouts/app.blade.php is a protected file and keeps its inline copy. That
 * copy runs during parsing; this module is deferred, so it reassigns the same
 * two names afterwards on pages using that layout. The implementations below
 * are a faithful copy of it, so which one wins makes no difference.
 *
 * Neither function reads a Blade-rendered value, which is why they can live in
 * a real JS file at all. Both build their DOM with createElement and
 * textContent - never innerHTML - so a message containing markup is shown as
 * text and cannot inject anything.
 */

window.showFlashError = function (message) {
    var container = document.getElementById('js-flash-container');
    if (!container) return;
    var existing = container.querySelector('.alert');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.className = 'alert danger';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('style', 'width:18px;height:18px;flex-shrink:0;margin-top:1px;');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '10');
    var l1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    l1.setAttribute('x1', '15'); l1.setAttribute('y1', '9'); l1.setAttribute('x2', '9'); l1.setAttribute('y2', '15');
    var l2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    l2.setAttribute('x1', '9'); l2.setAttribute('y1', '9'); l2.setAttribute('x2', '15'); l2.setAttribute('y2', '15');
    svg.appendChild(c); svg.appendChild(l1); svg.appendChild(l2);
    div.appendChild(svg);
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
};

window.showFlashSuccess = function (message) {
    var container = document.getElementById('js-flash-container');
    if (!container) return;
    var existing = container.querySelector('.alert');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.className = 'alert success';
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('style', 'width:18px;height:18px;flex-shrink:0;margin-top:1px;');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', 'M22 11.08V12a10 10 0 1 1-5.93-9.14');
    var pl = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    pl.setAttribute('points', '22 4 12 14.01 9 11.01');
    svg.appendChild(p); svg.appendChild(pl);
    div.appendChild(svg);
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
};
