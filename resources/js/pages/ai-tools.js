/* AI Tools (resources/views/ai-tools/index.blade.php).
 *
 * One job: the assistant tiles. A tile press writes its key into the
 * hidden input the form posts, marks itself pressed, and renames step
 * three to the assistant chosen, so the form reads "Paste this into
 * Cursor" before anything is created. Claude and ChatGPT sign in, so
 * their tile swaps naming and the key for the address to paste. The ticked reads are CSS (:has),
 * and the copy button is the global one from pages/settings.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('ai-tools-page');
    if (!page) return;

    var input = page.querySelector('[data-ai-assistant-input]');
    var title = page.querySelector('[data-ai-step3-title]');
    var tiles = Array.from(page.querySelectorAll('[data-ai-tile]'));
    if (!input || !tiles.length) return;

    var keySteps = page.querySelector('[data-ai-key-steps]');
    var signinSteps = page.querySelector('[data-ai-signin-steps]');

    function press(tile) {
        tiles.forEach(function (t) { t.setAttribute('aria-pressed', t === tile ? 'true' : 'false'); });
        input.value = tile.getAttribute('data-ai-tile');
        if (title) title.textContent = tile.getAttribute('data-ai-title') || '';

        // Claude and ChatGPT sign in: their steps replace naming and the key.
        var signsIn = tile.getAttribute('data-ai-signin') === '1';
        if (keySteps) {
            keySteps.hidden = signsIn;
            keySteps.querySelectorAll('input, button').forEach(function (el) { el.disabled = signsIn; });
        }
        if (signinSteps) {
            signinSteps.hidden = !signsIn;
            signinSteps.querySelectorAll('[data-ai-signin-for]').forEach(function (el) {
                el.hidden = el.getAttribute('data-ai-signin-for') !== input.value;
            });
        }
    }

    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () { press(tile); });
    });

    var current = tiles.find(function (t) { return t.getAttribute('data-ai-tile') === input.value; }) || tiles[0];
    press(current);
});
