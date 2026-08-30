/* AI Tools (resources/views/ai-tools/index.blade.php).
 *
 * One job: the assistant tiles. A tile press writes its key into the
 * hidden input the form posts, marks itself pressed, and renames step
 * three to the assistant chosen, so the form reads "Paste this into
 * Cursor" before anything is created. The ticked reads are CSS (:has),
 * and the copy button is the global one from pages/settings.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('ai-tools-page');
    if (!page) return;

    var input = page.querySelector('[data-ai-assistant-input]');
    var title = page.querySelector('[data-ai-step3-title]');
    var tiles = Array.from(page.querySelectorAll('[data-ai-tile]'));
    if (!input || !tiles.length) return;

    function press(tile) {
        tiles.forEach(function (t) { t.setAttribute('aria-pressed', t === tile ? 'true' : 'false'); });
        input.value = tile.getAttribute('data-ai-tile');
        if (title) title.textContent = tile.getAttribute('data-ai-title') || '';
    }

    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () { press(tile); });
    });

    var current = tiles.find(function (t) { return t.getAttribute('data-ai-tile') === input.value; }) || tiles[0];
    press(current);
});
