/* A ticked product reads as one block on every product table (a store's
   Listings and product group pages, the catalog list): the lines above its
   row, the row itself and its variation rows below carry is-picked.

   While any product on a table is ticked, the table is picking: a click
   anywhere on a product's block ticks or unticks it, and the links and
   buttons inside the block stand down until nothing on the table is ticked.
   Alpine writes the row boxes after a select-all, so the marks follow one
   tick later. Inert on a page with no tickable rows. */

const LINE = 'cc-err-row';
const VARIATIONS = ['cc-var-row', 'bl-varrow'];

const isVariation = (el) => VARIATIONS.some((c) => el.classList.contains(c));
const boxIn = (tr) => tr.querySelector('[data-row-check]');

/** The product row a table row belongs to: itself, the row under its lines, or the row above its variations. */
function productRowOf(tr) {
    if (boxIn(tr)) return tr;
    let el = null;
    if (tr.classList.contains(LINE)) {
        el = tr.nextElementSibling;
        while (el && el.classList.contains(LINE)) el = el.nextElementSibling;
    } else if (isVariation(tr)) {
        el = tr.previousElementSibling;
        while (el && isVariation(el)) el = el.previousElementSibling;
    }
    return el && boxIn(el) ? el : null;
}

/** Every row of a product's block. */
function blockOf(row) {
    const rows = [row];
    for (let el = row.previousElementSibling; el && el.classList.contains(LINE); el = el.previousElementSibling) rows.push(el);
    for (let el = row.nextElementSibling; el && isVariation(el); el = el.nextElementSibling) rows.push(el);
    return rows;
}

function markPicked() {
    const tables = new Set();
    document.querySelectorAll('[data-row-check]').forEach((box) => {
        const row = box.closest('tr');
        if (!row) return;
        const table = row.closest('table');
        if (table) tables.add(table);
        blockOf(row).forEach((tr) => {
            tr.classList.add('is-pickable');
            tr.classList.toggle('is-picked', box.checked);
        });
    });
    tables.forEach((table) => {
        table.classList.toggle('is-picking', table.querySelector('[data-row-check]:checked') !== null);
    });
}

let hovered = [];
function hover(rows) {
    hovered.forEach((tr) => tr.classList.remove('is-hover'));
    hovered = rows;
    hovered.forEach((tr) => tr.classList.add('is-hover'));
}

function init() {
    if (!document.querySelector('[data-row-check]')) return;

    document.addEventListener('change', (e) => {
        if (e.target && e.target.matches && e.target.matches('input[type="checkbox"]')) {
            setTimeout(markPicked, 0);
        }
    });

    // While picking, a click on a product's block is a tick, never a link or a button.
    document.addEventListener('click', (e) => {
        const target = e.target instanceof Element ? e.target : null;
        const table = target ? target.closest('table.is-picking') : null;
        if (!table || target.closest('[data-row-check]') || target.closest('thead')) return;
        const tr = target.closest('tr');
        const row = tr ? productRowOf(tr) : null;
        if (!row) return;
        e.preventDefault();
        e.stopPropagation();
        boxIn(row).click();
    }, true);

    // The block a click would tick, shown under the pointer while picking.
    document.addEventListener('pointerover', (e) => {
        const target = e.target instanceof Element ? e.target : null;
        const tr = target ? target.closest('table.is-picking tbody tr') : null;
        const row = tr ? productRowOf(tr) : null;
        hover(row ? blockOf(row) : []);
    });

    markPicked();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
