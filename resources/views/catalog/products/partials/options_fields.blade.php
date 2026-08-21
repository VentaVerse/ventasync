
{{--
  Variations builder (absolute pricing).
   - one axis:  editable rows, one per value (name / SKU / qty / price / cost)
   - two axes:  a combination grid, axis 1 x axis 2
   - global option and option_value records are found-or-created on save

  Wire, unchanged: a hidden `_options_format=absolute` marks the format, and
  beforeSubmit() generates the hidden inputs the controller reads -
  `option_name` + `values[i][name|sku|quantity|absolute_price|absolute_cost|
  option_value_id]` for one axis, `option1_name` + `option2_name` +
  `option1_values[i]` + `option2_values[i]` + `combinations[i][opt1|opt2|sku|
  quantity|absolute_price|absolute_cost]` for two. Every id the script binds
  to is unchanged; only classes moved to the `fm-` set. See
  ProductController::saveAbsoluteOptions.

  User-facing copy says Variation throughout, per CLAUDE.md. Code identifiers
  stay `option`.
--}}

<div id="options-error" class="fm-vars__errors d-none" role="alert"></div>

<div class="fm-vars" id="options-root">
    <input type="hidden" name="_options_format" value="absolute">

    <div class="fm-vars__row">
        {{-- Variation 1 --}}
        <div id="option1-group" class="d-none fm-vars__col">
            <div class="fm-vars__card">
                <div class="fm-vars__head">
                    <div class="fm-field">
                        <label class="fm-label" for="opt1-name-input">
                            Variation 1 <span class="fm-req">Required</span>
                        </label>
                        <input class="x-input" id="opt1-name-input" placeholder="Colour, cable length" autocomplete="off">
                    </div>
                    <x-ui.button type="button" variant="danger" size="sm" id="remove-opt1-btn">Remove</x-ui.button>
                </div>

                {{-- Values as an editable list. Only shown with two axes; with
                     one, the values are the rows of the grid below. --}}
                <div class="d-none" id="opt1-values-section">
                    <div class="fm-label">Values</div>
                    <div class="fm-vars__values" id="opt1-value-list"></div>
                    <div class="fm-vars__add">
                        <input class="x-input" id="opt1-new-value" placeholder="Value name, Enter to add"
                               aria-label="Add a value to variation 1" autocomplete="off">
                        <x-ui.button type="button" size="sm" id="opt1-add-value-btn">Add</x-ui.button>
                    </div>
                </div>
            </div>
        </div>

        <div id="add-opt2-wrapper" class="d-none fm-vars__mid">
            <x-ui.button type="button" size="sm" id="add-opt2-btn">
                <x-ui.icon name="plus" size="14" /> Second variation
            </x-ui.button>
        </div>

        {{-- Variation 2 --}}
        <div id="option2-group" class="d-none fm-vars__col">
            <div class="fm-vars__card">
                <div class="fm-vars__head">
                    <div class="fm-field">
                        <label class="fm-label" for="opt2-name-input">
                            Variation 2 <span class="fm-req">Required</span>
                        </label>
                        <input class="x-input" id="opt2-name-input" placeholder="Size, plug type" autocomplete="off">
                    </div>
                    <x-ui.button type="button" variant="danger" size="sm" id="remove-opt2-btn">Remove</x-ui.button>
                </div>

                <div>
                    <div class="fm-label">Values</div>
                    <div class="fm-vars__values" id="opt2-value-list"></div>
                    <div class="fm-vars__add">
                        <input class="x-input" id="opt2-new-value" placeholder="Value name, Enter to add"
                               aria-label="Add a value to variation 2" autocomplete="off">
                        <x-ui.button type="button" size="sm" id="opt2-add-value-btn">Add</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Values / combination grid --}}
    <div id="data-table-wrap" class="d-none">
        <div class="fm-vars__scroll">
            <table class="fm-vars__table" id="data-table">
                <thead id="data-thead"></thead>
                <tbody id="data-tbody"></tbody>
            </table>
        </div>
        <div class="fm-vars__add">
            <x-ui.button type="button" size="sm" id="add-row-btn">
                <x-ui.icon name="plus" size="14" /> Add value
            </x-ui.button>
        </div>
    </div>
</div>

<div id="add-option-wrapper">
    <x-ui.button type="button" id="add-option-btn">
        <x-ui.icon name="plus" size="14" /> Add a variation
    </x-ui.button>
</div>

@push('scripts')
<script>
'use strict';

const EXISTING_OPTION  = @json($existingOptions->first() ?? null);
const EXISTING_OPTION2 = @json($existingOptions->count() > 1 ? $existingOptions->get(1) : null);
const EXISTING_VALUES  = @json($existingOptions->first() ? ($existingOptions->first()->values ?? []) : []);
const EXISTING_VALUES2 = @json($existingOptions->count() > 1 ? ($existingOptions->get(1)->values ?? []) : []);
const EXISTING_COMBOS  = @json($existingCombinations ?? []);

// State
let opt1Values = []; // [{name, option_value_id?, sku, image, quantity, absolute_price, absolute_cost}]
let opt2Values = []; // [{name, option_value_id?}]
let comboData  = {}; // key "v1|v2" → {sku, image, quantity, absolute_price, absolute_cost} (2-option)
let mode       = 'none'; // 'none' | 'one' | 'two'

/* THE SHAPE THE TABLE IS CURRENTLY DRAWN IN, which is not always `mode`.
 *
 * saveTableToState() harvests the DOM back into state and has to read the
 * rows the way they were WRITTEN. It used to branch on `mode`, and every
 * mode transition sets `mode` before renderTable() redraws - so for one
 * call, `mode` said 'one' while the tbody still held combination rows.
 *
 * renderTable() opens by calling saveTableToState(), so that call landed in
 * the one-axis branch and harvested a 10-row combination grid into 5 single
 * values BY FLAT ROW INDEX: Small took (Small,Red), Medium took (Small,Blue)
 * which is empty, Large took (Medium,Red). Removing a second axis scrambled
 * and part-erased every value, which is the other half of the data loss and
 * the half that survived fixing the first.
 *
 * This is set at the END of every render, so it always describes what is on
 * screen rather than what is about to be. */
let renderedMode = 'none';

// ---- DOM refs ----
const $opt1Group      = document.getElementById('option1-group');
const $opt2Group      = document.getElementById('option2-group');
const $opt2Wrapper    = document.getElementById('add-opt2-wrapper');
const $addOptWrapper  = document.getElementById('add-option-wrapper');
const $tableWrap      = document.getElementById('data-table-wrap');
const $addRowBtn      = document.getElementById('add-row-btn');
const $thead          = document.getElementById('data-thead');
const $tbody          = document.getElementById('data-tbody');
const $opt1Name       = document.getElementById('opt1-name-input');
const $opt2Name       = document.getElementById('opt2-name-input');
const $opt1ValList    = document.getElementById('opt1-value-list');
const $opt1ValSection = document.getElementById('opt1-values-section');
const $opt2ValList    = document.getElementById('opt2-value-list');
const $optionsRoot    = document.getElementById('options-root');

// ---- Event delegation on tbody (catches all dynamic rows) ----
$tbody.addEventListener('input', function(e) {
    if (e.target.classList.contains('js-price') || e.target.classList.contains('js-cost')) {
        calcRowMetrics(e.target.closest('tr'));
    }
    if (e.target.classList.contains('js-qty')) {
        recalcProductQty();
    }
});

// ---- Helpers ----

function createEl(tag, attrs, textContent) {
    const el = document.createElement(tag);
    if (attrs) {
        Object.keys(attrs).forEach(key => {
            if (key === 'className') {
                el.className = attrs[key];
            } else if (key.startsWith('data-')) {
                el.dataset[key.slice(5)] = attrs[key];
            } else {
                el.setAttribute(key, attrs[key]);
            }
        });
    }
    if (textContent !== undefined) el.textContent = textContent;
    return el;
}

function createInput(className, type, value, attrs) {
    const el = createEl('input', Object.assign({ className: 'x-input ' + className, type: type || 'text' }, attrs || {}));
    el.value = value !== undefined && value !== null ? value : '';
    return el;
}

function clearChildren(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
}

function comboKey(v1, v2) {
    return v1 + '|' + (v2 || '');
}

// ---- Mode management ----

function setMode(newMode) {
    mode = newMode;
    $opt1Group.classList.toggle('d-none', mode === 'none');
    $opt2Group.classList.toggle('d-none', mode !== 'two');
    $opt2Wrapper.classList.toggle('d-none', mode !== 'one');
    $addOptWrapper.classList.toggle('d-none', mode !== 'none');
    $tableWrap.classList.toggle('d-none', mode === 'none');
    $addRowBtn.classList.toggle('d-none', mode !== 'one');
    // Variation 1 chip UI only in 2-option mode
    $opt1ValSection.classList.toggle('d-none', mode !== 'two');
    if (mode === 'two') renderOpt1List();
    renderTable();
    recalcProductQty();
}

// ---- Value list rendering (two axes: editable inputs) ----

function renderValueList(container, values, onRemove, onRename) {
    clearChildren(container);
    values.forEach((v, i) => {
        const row = createEl('div');
        const input = createInput('js-opt-val-name', 'text', v.name, { placeholder: 'Value name' });
        input.addEventListener('change', () => {
            const newName = input.value.trim();
            if (newName && newName !== v.name) {
                onRename(i, v.name, newName);
            }
        });
        row.appendChild(input);
        const removeBtn = createEl('button', { className: 'fm-vars__rowx', type: 'button', title: 'Remove', 'aria-label': 'Remove value' }, '\u00d7');
        removeBtn.addEventListener('click', () => onRemove(i));
        row.appendChild(removeBtn);
        container.appendChild(row);
    });
}

function shiftComboIndices(optNum, removedIdx) {
    const updated = {};
    Object.keys(comboData).forEach(key => {
        const [i1, i2] = key.split('|').map(Number);
        if (optNum === 1 && i1 === removedIdx) return;
        if (optNum === 2 && i2 === removedIdx) return;
        const newI1 = (optNum === 1 && i1 > removedIdx) ? i1 - 1 : i1;
        const newI2 = (optNum === 2 && i2 > removedIdx) ? i2 - 1 : i2;
        updated[comboKey(newI1, newI2)] = comboData[key];
    });
    comboData = updated;
}

function renderOpt1List() {
    renderValueList($opt1ValList, opt1Values,
        (i) => {
            saveTableToState();
            opt1Values.splice(i, 1);
            shiftComboIndices(1, i);
            renderOpt1List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            opt1Values[i].name = newName;
            renderTable();
        }
    );
}

function renderOpt2List() {
    renderValueList($opt2ValList, opt2Values,
        (i) => {
            saveTableToState();
            opt2Values.splice(i, 1);
            shiftComboIndices(2, i);
            renderOpt2List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            opt2Values[i].name = newName;
            renderTable();
        }
    );
}

function addOpt1Value(name) {
    name = (name || '').trim();
    opt1Values.push({ name, sku: '', image: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderOpt1List();
    renderTable();
    recalcProductQty();
    // Focus the newly added input for inline editing
    const inputs = $opt1ValList.querySelectorAll('.js-opt-val-name');
    const last = inputs[inputs.length - 1];
    if (last && !name) last.focus();
}

/* THE FIRST COLUMN INHERITS. THIS IS THE FIX FOR THE DATA LOSS.
 *
 * The grid only becomes a combination grid once the second axis has a value
 * (renderTable's `is2` requires opt2Values.length > 0), and renderTable reads
 * every cell from comboData, falling back to zeros for a missing key. On a
 * product that was one-axis, comboData is EMPTY - the real SKUs, quantities
 * and prices are in opt1Values - so the first value added to a second axis
 * rendered a grid of zeros. saveTableToState() then wrote those zeros back
 * over comboData, and Remove on the axis copied them into opt1Values, which
 * is why backing out did not undo it. The next click is Save, and zero stock
 * and zero price go to every marketplace.
 *
 * Seeding comboData[(i1, 0)] from opt1Values[i1] at the moment of conversion
 * makes it lossless: the first column keeps everything it had, and the
 * genuinely new combinations start empty, which is what they are.
 *
 * Guarded on comboData being empty so this only ever runs on the one-axis to
 * two-axis transition. A product that already has combinations arrives with
 * comboData populated from the server and must not be touched. */
function seedFirstColumnFromOneAxis() {
    if (Object.keys(comboData).length > 0) return;

    opt1Values.forEach((v1, i1) => {
        comboData[comboKey(i1, 0)] = {
            sku: v1.sku || '',
            image: v1.image || '',
            quantity: v1.quantity || 0,
            absolute_price: v1.absolute_price || 0,
            absolute_cost: v1.absolute_cost || 0,
        };
    });
}

function addOpt2Value(name) {
    name = (name || '').trim();

    // Before the first value turns this into a combination grid.
    if (opt2Values.length === 0) {
        saveTableToState();
        seedFirstColumnFromOneAxis();
    }

    opt2Values.push({ name });
    renderOpt2List();
    renderTable();
    recalcProductQty();
    const inputs = $opt2ValList.querySelectorAll('.js-opt-val-name');
    const last = inputs[inputs.length - 1];
    if (last && !name) last.focus();
}

// ---- Table state ----

function saveTableToState() {
    // renderedMode, not mode: read the rows as they were drawn.
    if (renderedMode === 'one') {
        $tbody.querySelectorAll('tr').forEach((row, i) => {
            if (i >= opt1Values.length) return;
            const nameInput = row.querySelector('.js-value-name');
            if (nameInput) opt1Values[i].name = nameInput.value.trim();
            const skuInput = row.querySelector('.js-sku');
            if (skuInput) opt1Values[i].sku = skuInput.value;
            const imgInput = row.querySelector('.js-image');
            if (imgInput) opt1Values[i].image = imgInput.value;
            const qtyInput = row.querySelector('.js-qty');
            if (qtyInput) opt1Values[i].quantity = parseInt(qtyInput.value || '0', 10);
            const priceInput = row.querySelector('.js-price');
            if (priceInput) opt1Values[i].absolute_price = parseFloat(priceInput.value || '0');
            const costInput = row.querySelector('.js-cost');
            if (costInput) opt1Values[i].absolute_cost = parseFloat(costInput.value || '0');
        });
    } else if (renderedMode === 'two') {
        $tbody.querySelectorAll('tr').forEach(row => {
            const key = row.dataset.comboKey;
            if (!key) return;
            comboData[key] = {
                sku: row.querySelector('.js-sku').value || '',
                image: (row.querySelector('.js-image') || {}).value || '',
                quantity: parseInt(row.querySelector('.js-qty').value || '0', 10),
                absolute_price: parseFloat(row.querySelector('.js-price').value || '0'),
                absolute_cost: parseFloat(row.querySelector('.js-cost').value || '0'),
            };
        });
    }
}

function calcRowMetrics(row) {
    const priceInput = row.querySelector('.js-price');
    const costInput  = row.querySelector('.js-cost');
    const price  = parseFloat(priceInput ? priceInput.value : 0) || 0;
    const cost   = parseFloat(costInput ? costInput.value : 0) || 0;
    const profit = price - cost;
    const margin = price > 0 ? (profit / price * 100) : 0;
    const markup = cost > 0 ? (profit / cost * 100) : 0;
    const cls    = profit >= 0 ? '' : 'fm-neg';

    const profitEl = row.querySelector('.js-profit');
    if (profitEl) {
        profitEl.textContent = profit.toFixed(2);
        profitEl.className = ('fm-num js-profit ' + cls).trim();
    }
    const marginEl = row.querySelector('.js-margin');
    if (marginEl) {
        marginEl.textContent = margin.toFixed(1) + '%';
        marginEl.className = ('fm-num js-margin ' + cls).trim();
    }
    const markupEl = row.querySelector('.js-markup');
    if (markupEl) {
        markupEl.textContent = markup.toFixed(1) + '%';
        markupEl.className = ('fm-num js-markup ' + cls).trim();
    }
}

function bindRowEvents(tr) {
    const priceEl = tr.querySelector('.js-price');
    const costEl  = tr.querySelector('.js-cost');
    const qtyEl   = tr.querySelector('.js-qty');
    const recalcPrice = () => calcRowMetrics(tr);
    const recalcQty = () => recalcProductQty();
    if (priceEl) { priceEl.addEventListener('input', recalcPrice); priceEl.addEventListener('change', recalcPrice); }
    if (costEl)  { costEl.addEventListener('input', recalcPrice); costEl.addEventListener('change', recalcPrice); }
    if (qtyEl)   { qtyEl.addEventListener('input', recalcQty); qtyEl.addEventListener('change', recalcQty); }
}

// ---- Table rendering (no innerHTML for user data) ----

function buildHeaderRow(is2) {
    const tr = document.createElement('tr');

    /* scope="col" on every header. Built headers had none, while the two
       hand-written tables on this surface scope all of theirs - so the one
       table a person is most likely to get lost in was the one with no column
       association at all. It does not name the inputs (a header never names a
       control inside a cell, which is what the aria-labels above are for) but
       it is what lets a screen reader answer "which column am I in". */
    const th = (cls, text) => createEl('th', { className: cls, scope: 'col' }, text);

    if (is2) {
        tr.appendChild(th('fm-vcol-opt', $opt1Name.value || 'Variation 1'));
        tr.appendChild(th('fm-vcol-opt', $opt2Name.value || 'Variation 2'));
    } else {
        tr.appendChild(th('fm-vcol-name', 'Value'));
    }
    tr.appendChild(th('fm-vcol-sku', 'SKU'));
    tr.appendChild(th('fm-vcol-qty', 'Qty'));
    tr.appendChild(th('fm-vcol-price', 'Price'));
    tr.appendChild(th('fm-vcol-cost', 'Cost'));
    /* PROFIT / MARGIN / MARKUP USED TO SIT HERE and they are gone on purpose.
       They were read-only, they restated the Pricing metrics strip further up
       the same form, and with cost at 0 - which is most of this catalogue -
       every row printed an identical "370.00 / 100.0% / 0.0%". Measured, they
       were 187px of the 780px this table gets, spent on three numbers that
       are the same on every line and that nobody can act on here.
       That width is what pays for the image. */
    tr.appendChild(th('fm-vcol-img', 'Image'));
    tr.appendChild(th('fm-vcol-act', ''));
    return tr;
}

/* The image cell.
 * ------------------------------------------------------------------
 * A button showing what this variation looks like, or the product's own
 * picture greyed back when it has none of its own. Empty is not an error and
 * is not blank: inheriting the parent is the normal case and the cell says so
 * rather than leaving a hole.
 *
 * The path travels in a hidden input beside the thumbnail, so it rides with
 * the row through every re-render and through submit exactly the way sku and
 * quantity do. That matters more here than it looks: saving a two-axis
 * product DELETES its combinations and re-inserts them, so an image the
 * server was holding but the form did not send would be dropped on the next
 * save.
 */
/* EVERY CELL IN THIS GRID SAYS WHAT IT IS.
 *
 * A five-value grid is 25 inputs; a realistic six-sizes-by-eight-colours one
 * is 192. None of them carried a label, an aria-label or a placeholder, and a
 * column header does not name a form control inside a cell - so to a screen
 * reader the whole grid was a field of anonymous boxes, in the one section
 * where getting the wrong row means shipping the wrong thing.
 *
 * The row identity is the value name, or both value names in a combination
 * grid, which is exactly how a person refers to these rows out loud: "the SKU
 * for Small, Red". Falls back to the position when a value has not been named
 * yet, because "SKU for value 3" still beats nothing. */
function labelFor(column, rowName, idx) {
    const who = (rowName && String(rowName).trim())
        ? String(rowName).trim()
        : ('value ' + ((idx || 0) + 1));

    return column + ' for ' + who;
}

function buildImageCell(imagePath, rowName, idx) {
    const td = createEl('td', { className: 'fm-vcol-img' });

    const hidden = createEl('input', { type: 'hidden', className: 'js-image' });
    hidden.value = imagePath || '';
    td.appendChild(hidden);

    const btn = createEl('button', {
        type: 'button',
        className: 'fm-vimg',
        title: 'Choose an image for this variation',
    });

    const img = createEl('img', { alt: '', loading: 'lazy' });

    /* A path that does not resolve is not a picture, and the browser's own
       broken-image glyph is the worst possible way to say so: it reads as a
       fault in the page rather than as a missing file. This catalogue was
       imported from a legacy store and carries plenty of references to files
       that are no longer on disk. Falling back to the empty state says the
       true thing - there is nothing to show here, pick something. */
    img.addEventListener('error', () => {
        if (img.parentNode) btn.removeChild(img);
        btn.classList.add('is-empty');
    });
    const clear = createEl('button', {
        type: 'button',
        className: 'fm-vimg__x',
        title: 'Use the product image instead',
        'aria-label': 'Use the product image instead',
    }, '\u00d7');

    function paint() {
        const own = hidden.value;
        const src = own || (window.pimPrimaryImage || '');

        if (src) {
            img.src = src.indexOf('/') === 0 || src.indexOf('http') === 0
                ? src
                : ('/storage/' + src.replace(/^\/+/, ''));
            img.classList.toggle('is-inherited', !own);
            if (!img.parentNode) btn.appendChild(img);
        } else if (img.parentNode) {
            btn.removeChild(img);
        }

        btn.classList.toggle('is-empty', !src);
        // The accessible name carries the state, since the thumbnail cannot.
        const who = (rowName && String(rowName).trim())
            ? String(rowName).trim()
            : ('value ' + ((idx || 0) + 1));
        btn.setAttribute('aria-label', own
            ? 'Change the image for ' + who
            : 'Choose an image for ' + who + '. It currently uses the product image.');
        clear.classList.toggle('d-none', !own);
    }

    btn.addEventListener('click', () => {
        if (typeof window.pimPickOne !== 'function') return;
        window.pimPickOne((picked) => {
            hidden.value = (picked && picked.path) || '';
            paint();
        });
    });

    clear.addEventListener('click', (e) => {
        e.stopPropagation();
        hidden.value = '';
        paint();
    });

    td.appendChild(btn);
    td.appendChild(clear);
    paint();

    // An inheriting cell shows the product's main image, so it has to follow
    // when somebody drags a different photo to the front.
    document.addEventListener('pim:primary-changed', () => {
        if (!hidden.value) paint();
    });

    return td;
}

function buildComboRow(i1, i2, v1Name, v2Name, data) {
    const tr = document.createElement('tr');
    tr.dataset.comboKey = comboKey(i1, i2);

    const td1 = createEl('td', { className: 'fm-vcol-value' }, v1Name);
    const td2 = createEl('td', { className: 'fm-vcol-value' }, v2Name);
    tr.appendChild(td1);
    tr.appendChild(td2);

    // "SKU for Small, Red" - the way a person refers to the row out loud.
    const rowName = [v1Name, v2Name].filter(Boolean).join(', ');

    const tdSku   = document.createElement('td');
    tdSku.appendChild(createInput('js-sku', 'text', data.sku,
        { 'aria-label': labelFor('SKU', rowName, i1) }));
    tr.appendChild(tdSku);

    const tdQty   = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', data.quantity,
        { 'aria-label': labelFor('Quantity', rowName, i1) }));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', data.absolute_price,
        { step: '0.01', 'aria-label': labelFor('Price', rowName, i1) }));
    tr.appendChild(tdPrice);

    const tdCost  = document.createElement('td');
    tdCost.appendChild(createInput('js-cost', 'number', data.absolute_cost,
        { step: '0.01', 'aria-label': labelFor('Cost', rowName, i1) }));
    tr.appendChild(tdCost);

    tr.appendChild(buildImageCell(data.image, rowName, i1));

    // A combination row has no per-row remove: the combinations are the
    // product of the two axes, so you remove a VALUE, not a cell.
    tr.appendChild(document.createElement('td'));

    bindRowEvents(tr);
    calcRowMetrics(tr);
    return tr;
}

function buildOneOptRow(v, idx) {
    const tr = document.createElement('tr');
    tr.dataset.rowIdx = idx;

    // Value name (editable input)
    const tdName = document.createElement('td');
    const nameInput = createInput('js-value-name', 'text', v.name, {
        placeholder: 'Value name',
        'aria-label': labelFor('Variation value name', v.name, idx),
    });
    tdName.appendChild(nameInput);
    tr.appendChild(tdName);

    const tdSku = document.createElement('td');
    tdSku.appendChild(createInput('js-sku', 'text', v.sku || '',
        { 'aria-label': labelFor('SKU', v.name, idx) }));
    tr.appendChild(tdSku);

    const tdQty = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', v.quantity || 0,
        { 'aria-label': labelFor('Quantity', v.name, idx) }));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', v.absolute_price || 0,
        { step: '0.01', 'aria-label': labelFor('Price', v.name, idx) }));
    tr.appendChild(tdPrice);

    const tdCost = document.createElement('td');
    tdCost.appendChild(createInput('js-cost', 'number', v.absolute_cost || 0,
        { step: '0.01', 'aria-label': labelFor('Cost', v.name, idx) }));
    tr.appendChild(tdCost);

    tr.appendChild(buildImageCell(v.image, v.name, idx));

    const tdActions = document.createElement('td');
    const removeBtn = createEl('button', { className: 'fm-vars__rowx', type: 'button', title: 'Remove', 'aria-label': 'Remove value' }, '\u00d7');
    removeBtn.addEventListener('click', () => {
        saveTableToState();
        opt1Values.splice(idx, 1);
        renderTable();
        recalcProductQty();
    });
    tdActions.appendChild(removeBtn);
    tr.appendChild(tdActions);

    bindRowEvents(tr);
    calcRowMetrics(tr);
    return tr;
}

function renderTable() {
    saveTableToState();
    clearChildren($thead);
    clearChildren($tbody);

    if (opt1Values.length === 0) {
        // Nothing drawn means nothing to harvest next time round.
        renderedMode = mode === 'one' ? 'one' : 'none';

        if (mode === 'one') {
            $tableWrap.classList.remove('d-none');
            $thead.appendChild(buildHeaderRow(false));
        } else {
            $tableWrap.classList.add('d-none');
        }
        return;
    }

    $tableWrap.classList.remove('d-none');

    const is2 = mode === 'two' && opt2Values.length > 0;

    $thead.appendChild(buildHeaderRow(is2));

    if (is2) {
        opt1Values.forEach((v1, i1) => {
            opt2Values.forEach((v2, i2) => {
                const key = comboKey(i1, i2);
                const d = comboData[key] || { sku: '', image: '', quantity: 0, absolute_price: 0, absolute_cost: 0 };
                $tbody.appendChild(buildComboRow(i1, i2, v1.name, v2.name, d));
            });
        });
    } else {
        opt1Values.forEach((v, idx) => {
            $tbody.appendChild(buildOneOptRow(v, idx));
        });
    }

    // Last, so it describes what is on screen rather than what was intended.
    renderedMode = is2 ? 'two' : 'one';
}

// ---- Product quantity sync ----

function recalcProductQty() {
    const qtyInput = document.getElementById('product_quantity') || document.querySelector('input[name="quantity"]');
    if (!qtyInput) return;
    const hint = document.getElementById('qty_hint');

    if (mode !== 'none' && opt1Values.length > 0) {
        let total = 0;
        $tbody.querySelectorAll('.js-qty').forEach(el => {
            const v = parseInt(el.value || '0', 10);
            if (!isNaN(v)) total += v;
        });
        qtyInput.readOnly = true;
        qtyInput.value = total;
        qtyInput.title = 'Worked out from the variation quantities';
        if (hint) hint.classList.remove('d-none');
    } else {
        qtyInput.readOnly = false;
        qtyInput.title = '';
        if (hint) hint.classList.add('d-none');
    }
}

// ---- beforeSubmit (generates hidden inputs for form) ----
// Reads directly from DOM inputs to guarantee submitted data matches what the user sees.

function beforeSubmit() {
    $optionsRoot.querySelectorAll('input[data-gen]').forEach(el => el.remove());

    if (mode === 'none' || opt1Values.length === 0) return;

    const addHidden = (name, value) => {
        const el = createEl('input', { type: 'hidden', name: name, 'data-gen': '1' });
        el.value = value;
        $optionsRoot.appendChild(el);
    };

    const opt1Name = $opt1Name.value.trim();
    if (!opt1Name) return;

    if (mode === 'two' && opt2Values.length > 0) {
        const opt2Name = $opt2Name.value.trim();
        if (!opt2Name) return;

        addHidden('option1_name', opt1Name);
        addHidden('option2_name', opt2Name);

        opt1Values.forEach((v, i) => addHidden('option1_values[' + i + ']', v.name));
        opt2Values.forEach((v, i) => addHidden('option2_values[' + i + ']', v.name));

        // Read combo data directly from DOM rows
        const comboRows = $tbody.querySelectorAll('tr[data-combo-key]');
        let ci = 0;
        comboRows.forEach(row => {
            const parts = row.dataset.comboKey.split('|').map(Number);
            addHidden('combinations[' + ci + '][opt1]', opt1Values[parts[0]] ? opt1Values[parts[0]].name : '');
            addHidden('combinations[' + ci + '][opt2]', opt2Values[parts[1]] ? opt2Values[parts[1]].name : '');
            addHidden('combinations[' + ci + '][sku]', (row.querySelector('.js-sku') || {}).value || '');
            addHidden('combinations[' + ci + '][image]', (row.querySelector('.js-image') || {}).value || '');
            addHidden('combinations[' + ci + '][quantity]', (row.querySelector('.js-qty') || {}).value || 0);
            addHidden('combinations[' + ci + '][absolute_price]', (row.querySelector('.js-price') || {}).value || 0);
            addHidden('combinations[' + ci + '][absolute_cost]', (row.querySelector('.js-cost') || {}).value || 0);
            ci++;
        });
    } else {
        addHidden('option_name', opt1Name);

        // Read values directly from DOM rows
        const rows = $tbody.querySelectorAll('tr');
        rows.forEach((row, idx) => {
            const nameInput = row.querySelector('.js-value-name');
            const name = nameInput ? nameInput.value.trim() : '';
            if (!name) return;

            addHidden('values[' + idx + '][name]', name);
            addHidden('values[' + idx + '][sku]', (row.querySelector('.js-sku') || {}).value || '');
            addHidden('values[' + idx + '][image]', (row.querySelector('.js-image') || {}).value || '');
            addHidden('values[' + idx + '][quantity]', (row.querySelector('.js-qty') || {}).value || 0);
            addHidden('values[' + idx + '][absolute_price]', (row.querySelector('.js-price') || {}).value || 0);
            addHidden('values[' + idx + '][absolute_cost]', (row.querySelector('.js-cost') || {}).value || 0);

            // Preserve option_value_id for existing values
            if (idx < opt1Values.length && opt1Values[idx].option_value_id) {
                addHidden('values[' + idx + '][option_value_id]', opt1Values[idx].option_value_id);
            }
        });
    }
}

// ---- Event listeners ----

document.getElementById('add-option-btn').addEventListener('click', () => {
    setMode('one');
    $opt1Name.focus();
});

document.getElementById('remove-opt1-btn').addEventListener('click', () => {
    opt1Values = [];
    opt2Values = [];
    comboData = {};
    renderOpt1List();
    renderOpt2List();
    $opt1Name.value = '';
    $opt2Name.value = '';
    setMode('none');
});

document.getElementById('add-opt2-btn').addEventListener('click', () => {
    /* A second axis multiplies the grid, and that is worth saying out loud
       before someone invests in naming it. Not a warning - with the seed
       above, nothing is lost - but "5 values" becoming "10 combinations you
       now have to fill in" is a change of shape a person should agree to.

       Primary tone, not danger: this destroys nothing. The dialog is the same
       one Delete product and the cost override already use. */
    const existing = opt1Values.length;

    if (existing === 0 || typeof window.confirmModal !== 'function') {
        setMode('two');
        $opt2Name.focus();
        return;
    }

    window.confirmModal(
        'Add a second variation? Your ' + existing + ' value' + (existing === 1 ? '' : 's')
        + ' become combinations of the two. The first column keeps its SKU, stock and price;'
        + ' the new combinations start empty.',
        document.getElementById('add-opt2-btn')
    ).then((ok) => {
        if (!ok) return;
        setMode('two');
        $opt2Name.focus();
    });
});

document.getElementById('remove-opt2-btn').addEventListener('click', () => {
    saveTableToState();
    /* Carry the first combination column back to the single axis.
     *
     * This is what made the data loss permanent: it happily copied a row of
     * zeros over a value that had real stock and a real price, so Remove -
     * the control a person reaches for to undo - completed the damage instead
     * of reversing it. It cannot write an empty row over a non-empty value
     * any more. Clearing a value's fields deliberately and then removing the
     * axis still works, because then the value itself is empty too. */
    opt1Values.forEach((v1, i1) => {
        const first = comboData[comboKey(i1, 0)];
        if (!first) return;

        const firstIsEmpty = !first.sku
            && !Number(first.quantity)
            && !Number(first.absolute_price)
            && !Number(first.absolute_cost);

        const valueHasData = !!v1.sku
            || !!Number(v1.quantity)
            || !!Number(v1.absolute_price)
            || !!Number(v1.absolute_cost);

        if (firstIsEmpty && valueHasData) return;

        v1.sku = first.sku || '';
        v1.image = first.image || '';
        v1.quantity = first.quantity || 0;
        v1.absolute_price = first.absolute_price || 0;
        v1.absolute_cost = first.absolute_cost || 0;
    });
    opt2Values = [];
    renderOpt2List();
    $opt2Name.value = '';
    comboData = {};
    setMode('one');
});

$addRowBtn.addEventListener('click', () => {
    saveTableToState();
    opt1Values.push({ name: '', sku: '', image: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderTable();
    const rows = $tbody.querySelectorAll('tr');
    const lastRow = rows[rows.length - 1];
    if (lastRow) {
        const nameInput = lastRow.querySelector('.js-value-name');
        if (nameInput) nameInput.focus();
    }
    recalcProductQty();
});

// Opt1 add value (2-option mode)
function handleAddOpt1() {
    const input = document.getElementById('opt1-new-value');
    addOpt1Value(input.value);
    input.value = '';
    input.focus();
}
document.getElementById('opt1-add-value-btn').addEventListener('click', handleAddOpt1);
document.getElementById('opt1-new-value').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); handleAddOpt1(); }
});

// Opt2 add value
function handleAddOpt2() {
    const input = document.getElementById('opt2-new-value');
    addOpt2Value(input.value);
    input.value = '';
    input.focus();
}
document.getElementById('opt2-add-value-btn').addEventListener('click', handleAddOpt2);
document.getElementById('opt2-new-value').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); handleAddOpt2(); }
});

// Form submit hook
const form = $optionsRoot.closest('form');
if (form) {
    const $optError = document.getElementById('options-error');

    function showOptError(messages) {
        $optError.textContent = '';
        messages.forEach(m => {
            const div = document.createElement('div');
            div.textContent = m;
            $optError.appendChild(div);
        });
        $optError.classList.remove('d-none');
        $optError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearOptError() {
        $optError.textContent = '';
        $optError.classList.add('d-none');
        $tbody.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
        $opt1Name.classList.remove('input-error');
        $opt2Name.classList.remove('input-error');
    }

    const checkSkusUrl = @json(route('products.check_skus'));
    const excludeProductId = {{ isset($product) ? (int) $product->product_id : 0 }};

    form.addEventListener('submit', (e) => {
        recalcProductQty();
        clearOptError();

        if (mode === 'none') {
            beforeSubmit();
            return;
        }

        e.preventDefault();

        const errors = [];
        const seenSkus = {};
        const parentSku = (form.querySelector('input[name="sku"]') || {}).value || '';
        const parentSkuLower = parentSku.trim().toLowerCase();
        let firstBadInput = null;
        const allSkus = [];

        // Option name(s) must not be empty
        if (!$opt1Name.value.trim()) {
            errors.push('Variation 1 name is required.');
            $opt1Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt1Name;
        }
        if (mode === 'two' && !$opt2Name.value.trim()) {
            errors.push('Variation 2 name is required.');
            $opt2Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt2Name;
        }

        // Variation 1 and Variation 2 names must differ
        if (mode === 'two' && $opt1Name.value.trim() && $opt2Name.value.trim()
            && $opt1Name.value.trim().toLowerCase() === $opt2Name.value.trim().toLowerCase()) {
            errors.push('Variation 1 and Variation 2 cannot have the same name.');
            $opt2Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt2Name;
        }

        if (opt1Values.length === 0) {
            errors.push('Variation 1 must have at least one value.');
        }
        if (mode === 'two' && opt2Values.length === 0) {
            errors.push('Variation 2 must have at least one value.');
        }

        // Duplicate value names within Variation 1
        const seen1 = {};
        opt1Values.forEach(v => {
            const lower = (v.name || '').trim().toLowerCase();
            if (lower && seen1[lower]) {
                errors.push('Duplicate value name "' + v.name.trim() + '" in Variation 1.');
            }
            if (lower) seen1[lower] = true;
        });

        // Duplicate value names within Variation 2
        if (mode === 'two') {
            const seen2 = {};
            opt2Values.forEach(v => {
                const lower = (v.name || '').trim().toLowerCase();
                if (lower && seen2[lower]) {
                    errors.push('Duplicate value name "' + v.name.trim() + '" in Variation 2.');
                }
                if (lower) seen2[lower] = true;
            });
        }

        const skuInputMap = {};
        $tbody.querySelectorAll('tr').forEach(row => {
            const nameInput = row.querySelector('.js-value-name');
            const skuInput = row.querySelector('.js-sku');
            const name = nameInput ? nameInput.value.trim() : '';
            const sku = skuInput ? skuInput.value.trim() : '';

            if (nameInput && !name) {
                nameInput.classList.add('input-error');
                if (!firstBadInput) firstBadInput = nameInput;
            }

            if (skuInput && !sku) {
                skuInput.classList.add('input-error');
                if (!firstBadInput) firstBadInput = skuInput;
            }

            if (sku) {
                const skuLower = sku.toLowerCase();
                if (parentSkuLower && skuLower === parentSkuLower) {
                    errors.push('SKU "' + sku + '" is already used as this product\'s parent SKU.');
                    if (skuInput) skuInput.classList.add('input-error');
                    if (!firstBadInput) firstBadInput = skuInput;
                } else if (seenSkus[skuLower]) {
                    errors.push('Duplicate SKU "' + sku + '" within this product\'s options.');
                    if (skuInput) skuInput.classList.add('input-error');
                    if (!firstBadInput) firstBadInput = skuInput;
                } else {
                    allSkus.push(sku);
                    skuInputMap[skuLower] = skuInput;
                }
                seenSkus[skuLower] = true;
            }
        });

        if ($tbody.querySelectorAll('.js-value-name.input-error').length > 0) {
            errors.unshift('All variation values must have a name.');
        }
        if ($tbody.querySelectorAll('.js-sku.input-error').length > 0 && !errors.some(e => e.includes('SKU'))) {
            errors.push('All variation values must have a SKU.');
        }

        if (errors.length > 0) {
            showOptError(errors);
            if (firstBadInput) firstBadInput.focus();
            return;
        }

        // AJAX cross-product SKU uniqueness check
        if (allSkus.length === 0) {
            beforeSubmit();
            form.submit();
            return;
        }

        const body = new FormData();
        allSkus.forEach(s => body.append('skus[]', s));
        if (excludeProductId) body.append('exclude_product_id', excludeProductId);
        body.append('_token', form.querySelector('input[name="_token"]').value);

        fetch(checkSkusUrl, { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                const taken = data.taken || {};
                const remoteErrors = [];
                let remoteBad = null;
                for (const [key, type] of Object.entries(taken)) {
                    const input = skuInputMap[key];
                    const label = type === 'option_value' ? "another product's variation value" : 'another product';
                    remoteErrors.push('SKU "' + (input ? input.value.trim() : key) + '" is already used by ' + label + '.');
                    if (input) { input.classList.add('input-error'); if (!remoteBad) remoteBad = input; }
                }
                if (remoteErrors.length > 0) {
                    showOptError(remoteErrors);
                    if (remoteBad) remoteBad.focus();
                    return;
                }
                beforeSubmit();
                form.submit();
            })
            .catch(() => {
                beforeSubmit();
                form.submit();
            });
    });
}

// ---- Load existing data ----

if (EXISTING_OPTION) {
    EXISTING_VALUES.forEach(v => {
        opt1Values.push({
            name: v.option_value_name || v.name,
            option_value_id: v.option_value_id,
            sku: v.sku || '',
            image: v.image || '',
            quantity: v.quantity || 0,
            absolute_price: parseFloat(v.absolute_price) || 0,
            absolute_cost: parseFloat(v.absolute_cost) || 0,
        });
    });

    $opt1Name.value = EXISTING_OPTION.option_name || EXISTING_OPTION.name || '';

    if (EXISTING_OPTION2) {
        EXISTING_VALUES2.forEach(v => {
            opt2Values.push({ name: v.option_value_name || v.name, option_value_id: v.option_value_id });
        });
        $opt2Name.value = EXISTING_OPTION2.option_name || EXISTING_OPTION2.name || '';
        renderOpt1List();
        renderOpt2List();

        if (EXISTING_COMBOS.length > 0) {
            EXISTING_COMBOS.forEach(c => {
                const i1 = opt1Values.findIndex(v => v.name === c.opt1_name);
                const i2 = opt2Values.findIndex(v => v.name === c.opt2_name);
                if (i1 >= 0 && i2 >= 0) {
                    comboData[comboKey(i1, i2)] = {
                        sku: c.sku || '',
                        image: c.image || '',
                        quantity: c.quantity || 0,
                        absolute_price: parseFloat(c.absolute_price) || 0,
                        absolute_cost: parseFloat(c.absolute_cost) || 0,
                    };
                }
            });
        }
        setMode('two');
    } else {
        setMode('one');
    }
} else {
    recalcProductQty();
}

// ---- Highlight duplicate SKU fields on validation error ----
@if($errors->has('option_sku'))
(function() {
    const errorSkus = @json(
        collect($errors->get('option_sku'))->map(function($msg) {
            if (preg_match('/SKU "([^"]+)"/', $msg, $m)) return strtolower($m[1]);
            return null;
        })->filter()->unique()->values()
    );
    if (!errorSkus.length) return;
    const skuInputs = document.querySelectorAll('#data-table .js-sku');
    skuInputs.forEach(input => {
        if (errorSkus.includes(input.value.trim().toLowerCase())) {
            input.classList.add('input-error');
            const errDiv = document.createElement('div');
            errDiv.className = 'fm-error';
            errDiv.textContent = 'Duplicate SKU';
            input.parentNode.appendChild(errDiv);
        }
    });
})();
@endif
</script>
@endpush
