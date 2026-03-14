
{{--
  Absolute Pricing Options Builder
  - 1-option: editable rows (name/sku/qty/price/cost per value)
  - 2-option: combination grid (Option1 x Option2)
  - Auto-creates global option/value records on save
--}}

<div id="options-error" class="alert danger d-none" style="margin-bottom:12px;"></div>
<div id="options-root">
    <input type="hidden" name="_options_format" value="absolute">
    {{-- Option 1 + Option 2 side by side --}}
    <div class="opt-row">
        {{-- Option 1 --}}
        <div id="option1-group" class="d-none opt-col">
            <div class="card opt-card">
                <div class="d-flex gap-10 flex-wrap opt-header">
                    <div style="flex:1; min-width:160px;">
                        <label class="required">Option 1 Name</label>
                        <input class="input" id="opt1-name-input" placeholder="e.g. Cable Length, Color" autocomplete="off">
                    </div>
                    <div>
                        <button class="btn danger opt-btn-remove" type="button" id="remove-opt1-btn">Remove</button>
                    </div>
                </div>

                {{-- Option 1 values (editable list — visible in 2-option mode only) --}}
                <div class="mt-8 d-none" id="opt1-values-section">
                    <label>Values</label>
                    <div id="opt1-value-list"></div>
                    <div class="d-flex gap-6 mt-4 opt-add-value-wrap">
                        <input class="input" id="opt1-new-value" placeholder="Value name, Enter to add" autocomplete="off">
                        <button class="btn secondary opt-btn-sm" type="button" id="opt1-add-value-btn">Add</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Add Option 2 button (beside opt1 card) --}}
        <div id="add-opt2-wrapper" class="d-none opt-add-opt2">
            <button class="btn secondary opt-btn-sm" type="button" id="add-opt2-btn">+ Add Option 2</button>
        </div>

        {{-- Option 2 (2-option mode only) --}}
        <div id="option2-group" class="d-none opt-col">
            <div class="card opt-card">
                <div class="d-flex gap-10 flex-wrap opt-header">
                    <div style="flex:1; min-width:160px;">
                        <label class="required">Option 2 Name</label>
                        <input class="input" id="opt2-name-input" placeholder="e.g. Size, Plug Type" autocomplete="off">
                    </div>
                    <div>
                        <button class="btn danger opt-btn-remove" type="button" id="remove-opt2-btn">Remove</button>
                    </div>
                </div>

                <div class="mt-8">
                    <label>Values</label>
                    <div id="opt2-value-list"></div>
                    <div class="d-flex gap-6 mt-4 opt-add-value-wrap">
                        <input class="input" id="opt2-new-value" placeholder="Value name, Enter to add" autocomplete="off">
                        <button class="btn secondary opt-btn-sm" type="button" id="opt2-add-value-btn">Add</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Values / Combination table --}}
    <div id="data-table-wrap" class="d-none mt-12">
        <div class="opt-table-scroll">
            <table class="table opt-table" id="data-table">
                <thead id="data-thead"></thead>
                <tbody id="data-tbody"></tbody>
            </table>
        </div>
        <div class="mt-6">
            <button class="btn secondary opt-btn-sm" type="button" id="add-row-btn">+ Add Value</button>
        </div>
    </div>
</div>

<div id="add-option-wrapper" class="mt-12">
    <button class="btn secondary" type="button" id="add-option-btn">Add Option</button>
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
let opt1Values = []; // [{name, option_value_id?, sku, quantity, absolute_price, absolute_cost}]
let opt2Values = []; // [{name, option_value_id?}]
let comboData  = {}; // key "v1|v2" → {sku, quantity, absolute_price, absolute_cost} (2-option)
let mode       = 'none'; // 'none' | 'one' | 'two'

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
    const el = createEl('input', Object.assign({ className: 'input ' + className, type: type || 'text' }, attrs || {}));
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
    // Option 1 chip UI only in 2-option mode
    $opt1ValSection.classList.toggle('d-none', mode !== 'two');
    if (mode === 'two') renderOpt1List();
    renderTable();
    recalcProductQty();
}

// ---- Value list rendering (2-option mode — editable inputs) ----

function renderValueList(container, values, onRemove, onRename) {
    clearChildren(container);
    values.forEach((v, i) => {
        const row = createEl('div', { className: 'd-flex gap-6 items-center mt-4' });
        const input = createInput('js-opt-val-name', 'text', v.name, { placeholder: 'Value name' });
        input.addEventListener('change', () => {
            const newName = input.value.trim();
            if (newName && newName !== v.name) {
                onRename(i, v.name, newName);
            }
        });
        row.appendChild(input);
        const removeBtn = createEl('button', { className: 'btn danger opt-btn-row-remove', type: 'button', title: 'Remove' }, '\u00d7');
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
    opt1Values.push({ name, sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderOpt1List();
    renderTable();
    recalcProductQty();
    // Focus the newly added input for inline editing
    const inputs = $opt1ValList.querySelectorAll('.js-opt-val-name');
    const last = inputs[inputs.length - 1];
    if (last && !name) last.focus();
}

function addOpt2Value(name) {
    name = (name || '').trim();
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
    if (mode === 'one') {
        $tbody.querySelectorAll('tr').forEach((row, i) => {
            if (i >= opt1Values.length) return;
            const nameInput = row.querySelector('.js-value-name');
            if (nameInput) opt1Values[i].name = nameInput.value.trim();
            const skuInput = row.querySelector('.js-sku');
            if (skuInput) opt1Values[i].sku = skuInput.value;
            const qtyInput = row.querySelector('.js-qty');
            if (qtyInput) opt1Values[i].quantity = parseInt(qtyInput.value || '0', 10);
            const priceInput = row.querySelector('.js-price');
            if (priceInput) opt1Values[i].absolute_price = parseFloat(priceInput.value || '0');
            const costInput = row.querySelector('.js-cost');
            if (costInput) opt1Values[i].absolute_cost = parseFloat(costInput.value || '0');
        });
    } else if (mode === 'two') {
        $tbody.querySelectorAll('tr').forEach(row => {
            const key = row.dataset.comboKey;
            if (!key) return;
            comboData[key] = {
                sku: row.querySelector('.js-sku').value || '',
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
    const cls    = profit >= 0 ? 'opt-metric-positive' : 'opt-metric-negative';

    const profitEl = row.querySelector('.js-profit');
    if (profitEl) {
        profitEl.textContent = profit.toFixed(2);
        profitEl.className = 'opt-metric js-profit ' + cls;
    }
    const marginEl = row.querySelector('.js-margin');
    if (marginEl) {
        marginEl.textContent = margin.toFixed(1) + '%';
        marginEl.className = 'opt-metric js-margin ' + cls;
    }
    const markupEl = row.querySelector('.js-markup');
    if (markupEl) {
        markupEl.textContent = markup.toFixed(1) + '%';
        markupEl.className = 'opt-metric js-markup ' + cls;
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
    if (is2) {
        const th1 = createEl('th', { className: 'opt-col-opt' }, $opt1Name.value || 'Option 1');
        const th2 = createEl('th', { className: 'opt-col-opt' }, $opt2Name.value || 'Option 2');
        tr.appendChild(th1);
        tr.appendChild(th2);
    } else {
        tr.appendChild(createEl('th', { className: 'opt-col-value' }, 'Value'));
    }
    tr.appendChild(createEl('th', { className: 'opt-col-sku' }, 'SKU'));
    tr.appendChild(createEl('th', { className: 'opt-col-qty' }, 'Qty'));
    tr.appendChild(createEl('th', { className: 'opt-col-price' }, 'Price'));
    tr.appendChild(createEl('th', { className: 'opt-col-cost' }, 'Cost'));
    tr.appendChild(createEl('th', { className: 'opt-col-profit' }, 'Profit'));
    tr.appendChild(createEl('th', { className: 'opt-col-margin' }, 'Margin'));
    tr.appendChild(createEl('th', { className: 'opt-col-markup' }, 'Markup'));
    tr.appendChild(createEl('th', { className: 'opt-col-actions' }));
    return tr;
}

function buildComboRow(i1, i2, v1Name, v2Name, data) {
    const tr = document.createElement('tr');
    tr.dataset.comboKey = comboKey(i1, i2);

    const td1 = createEl('td', { className: 'opt-value-label' }, v1Name);
    const td2 = createEl('td', { className: 'opt-value-label' }, v2Name);
    tr.appendChild(td1);
    tr.appendChild(td2);

    const tdSku   = document.createElement('td');
    tdSku.appendChild(createInput('js-sku', 'text', data.sku));
    tr.appendChild(tdSku);

    const tdQty   = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', data.quantity));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', data.absolute_price, { step: '0.01' }));
    tr.appendChild(tdPrice);

    const tdCost  = document.createElement('td');
    tdCost.appendChild(createInput('js-cost', 'number', data.absolute_cost, { step: '0.01' }));
    tr.appendChild(tdCost);

    const tdProfit = document.createElement('td');
    tdProfit.appendChild(createEl('span', { className: 'opt-metric js-profit' }, '\u2014'));
    tr.appendChild(tdProfit);

    const tdMargin = document.createElement('td');
    tdMargin.appendChild(createEl('span', { className: 'opt-metric js-margin' }, '\u2014'));
    tr.appendChild(tdMargin);

    const tdMarkup = document.createElement('td');
    tdMarkup.appendChild(createEl('span', { className: 'opt-metric js-markup' }, '\u2014'));
    tr.appendChild(tdMarkup);

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
    const nameInput = createInput('js-value-name', 'text', v.name, { placeholder: 'Value name' });
    tdName.appendChild(nameInput);
    tr.appendChild(tdName);

    const tdSku = document.createElement('td');
    tdSku.appendChild(createInput('js-sku', 'text', v.sku || ''));
    tr.appendChild(tdSku);

    const tdQty = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', v.quantity || 0));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', v.absolute_price || 0, { step: '0.01' }));
    tr.appendChild(tdPrice);

    const tdCost = document.createElement('td');
    tdCost.appendChild(createInput('js-cost', 'number', v.absolute_cost || 0, { step: '0.01' }));
    tr.appendChild(tdCost);

    const tdProfit = document.createElement('td');
    tdProfit.appendChild(createEl('span', { className: 'opt-metric js-profit' }, '\u2014'));
    tr.appendChild(tdProfit);

    const tdMargin = document.createElement('td');
    tdMargin.appendChild(createEl('span', { className: 'opt-metric js-margin' }, '\u2014'));
    tr.appendChild(tdMargin);

    const tdMarkup = document.createElement('td');
    tdMarkup.appendChild(createEl('span', { className: 'opt-metric js-markup' }, '\u2014'));
    tr.appendChild(tdMarkup);

    const tdActions = document.createElement('td');
    const removeBtn = createEl('button', { className: 'btn danger opt-btn-row-remove', type: 'button', title: 'Remove' }, '\u00d7');
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
                const d = comboData[key] || { sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 };
                $tbody.appendChild(buildComboRow(i1, i2, v1.name, v2.name, d));
            });
        });
    } else {
        opt1Values.forEach((v, idx) => {
            $tbody.appendChild(buildOneOptRow(v, idx));
        });
    }
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
        qtyInput.classList.add('opt-qty-readonly');
        qtyInput.title = 'Auto-calculated from option quantities';
        if (hint) hint.classList.remove('d-none');
    } else {
        qtyInput.readOnly = false;
        qtyInput.classList.remove('opt-qty-readonly');
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
    setMode('two');
    $opt2Name.focus();
});

document.getElementById('remove-opt2-btn').addEventListener('click', () => {
    saveTableToState();
    // Carry first combo column data back to opt1Values
    opt1Values.forEach((v1, i1) => {
        const first = comboData[comboKey(i1, 0)];
        if (first) {
            v1.sku = first.sku || '';
            v1.quantity = first.quantity || 0;
            v1.absolute_price = first.absolute_price || 0;
            v1.absolute_cost = first.absolute_cost || 0;
        }
    });
    opt2Values = [];
    renderOpt2List();
    $opt2Name.value = '';
    comboData = {};
    setMode('one');
});

$addRowBtn.addEventListener('click', () => {
    saveTableToState();
    opt1Values.push({ name: '', sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
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
            errors.push('Option 1 name is required.');
            $opt1Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt1Name;
        }
        if (mode === 'two' && !$opt2Name.value.trim()) {
            errors.push('Option 2 name is required.');
            $opt2Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt2Name;
        }

        // Option 1 and Option 2 names must differ
        if (mode === 'two' && $opt1Name.value.trim() && $opt2Name.value.trim()
            && $opt1Name.value.trim().toLowerCase() === $opt2Name.value.trim().toLowerCase()) {
            errors.push('Option 1 and Option 2 cannot have the same name.');
            $opt2Name.classList.add('input-error');
            if (!firstBadInput) firstBadInput = $opt2Name;
        }

        if (opt1Values.length === 0) {
            errors.push('Option 1 must have at least one value.');
        }
        if (mode === 'two' && opt2Values.length === 0) {
            errors.push('Option 2 must have at least one value.');
        }

        // Duplicate value names within Option 1
        const seen1 = {};
        opt1Values.forEach(v => {
            const lower = (v.name || '').trim().toLowerCase();
            if (lower && seen1[lower]) {
                errors.push('Duplicate value name "' + v.name.trim() + '" in Option 1.');
            }
            if (lower) seen1[lower] = true;
        });

        // Duplicate value names within Option 2
        if (mode === 'two') {
            const seen2 = {};
            opt2Values.forEach(v => {
                const lower = (v.name || '').trim().toLowerCase();
                if (lower && seen2[lower]) {
                    errors.push('Duplicate value name "' + v.name.trim() + '" in Option 2.');
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
            errors.unshift('All option values must have a name.');
        }
        if ($tbody.querySelectorAll('.js-sku.input-error').length > 0 && !errors.some(e => e.includes('SKU'))) {
            errors.push('All option values must have a SKU.');
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
                    const label = type === 'option_value' ? "another product's option value" : 'another product';
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
            errDiv.className = 'field-error';
            errDiv.textContent = 'Duplicate SKU';
            input.parentNode.appendChild(errDiv);
        }
    });
})();
@endif
</script>
@endpush
