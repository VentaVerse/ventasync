@if(session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="alert error">{{ session('error') }}</div>
@endif
@error('option_sku')
    <div class="alert error">{{ $message }}</div>
@enderror

{{--
  Absolute Pricing Options Builder
  - 1-option: editable rows (name/sku/qty/price/cost per value)
  - 2-option: combination grid (Option1 x Option2)
  - Auto-creates global option/value records on save
--}}

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

                {{-- Add Option 2 button (inside opt1 card) --}}
                <div id="add-opt2-wrapper" class="d-none mt-8">
                    <button class="btn secondary opt-btn-sm" type="button" id="add-opt2-btn">+ Add Option 2</button>
                </div>
            </div>
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
    $tableWrap.classList.toggle('d-none', mode === 'none' || opt1Values.length === 0);
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

function remapComboKeys(optNum, oldName, newName) {
    const updated = {};
    Object.keys(comboData).forEach(key => {
        const parts = key.split('|');
        if (optNum === 1 && parts[0] === oldName) {
            updated[newName + '|' + (parts[1] || '')] = comboData[key];
        } else if (optNum === 2 && parts[1] === oldName) {
            updated[parts[0] + '|' + newName] = comboData[key];
        } else {
            updated[key] = comboData[key];
        }
    });
    comboData = updated;
}

function renderOpt1List() {
    renderValueList($opt1ValList, opt1Values,
        (i) => {
            saveTableToState();
            opt1Values.splice(i, 1);
            renderOpt1List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            remapComboKeys(1, oldName, newName);
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
            renderOpt2List();
            renderTable();
            recalcProductQty();
        },
        (i, oldName, newName) => {
            saveTableToState();
            remapComboKeys(2, oldName, newName);
            opt2Values[i].name = newName;
            renderTable();
        }
    );
}

function addOpt1Value(name) {
    name = (name || '').trim();
    if (!name || opt1Values.some(v => v.name.toLowerCase() === name.toLowerCase())) return;
    opt1Values.push({ name, sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 });
    renderOpt1List();
    renderTable();
    recalcProductQty();
}

function addOpt2Value(name) {
    name = (name || '').trim();
    if (!name || opt2Values.some(v => v.name.toLowerCase() === name.toLowerCase())) return;
    opt2Values.push({ name });
    renderOpt2List();
    renderTable();
    recalcProductQty();
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
    const price  = parseFloat(row.querySelector('.js-price').value) || 0;
    const cost   = parseFloat(row.querySelector('.js-cost').value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? (profit / price * 100) : 0;
    const cls    = profit >= 0 ? 'opt-metric-positive' : 'opt-metric-negative';

    const profitEl = row.querySelector('.js-profit');
    if (profitEl) {
        profitEl.textContent = profit.toFixed(2);
        profitEl.className = 'opt-metric ' + cls;
    }
    const marginEl = row.querySelector('.js-margin');
    if (marginEl) {
        marginEl.textContent = margin.toFixed(1) + '%';
        marginEl.className = 'opt-metric ' + cls;
    }
}

function bindRowEvents(tr) {
    const priceEl = tr.querySelector('.js-price');
    const costEl  = tr.querySelector('.js-cost');
    const qtyEl   = tr.querySelector('.js-qty');
    if (priceEl) priceEl.addEventListener('input', () => calcRowMetrics(tr));
    if (costEl)  costEl.addEventListener('input', () => calcRowMetrics(tr));
    if (qtyEl)   qtyEl.addEventListener('input', () => recalcProductQty());
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
    tr.appendChild(createEl('th', { className: 'opt-col-actions' }));
    return tr;
}

function buildComboRow(v1Name, v2Name, data) {
    const tr = document.createElement('tr');
    tr.dataset.comboKey = comboKey(v1Name, v2Name);

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
        $tableWrap.classList.add('d-none');
        return;
    }

    $tableWrap.classList.remove('d-none');

    const is2 = mode === 'two' && opt2Values.length > 0;

    $thead.appendChild(buildHeaderRow(is2));

    if (is2) {
        opt1Values.forEach(v1 => {
            opt2Values.forEach(v2 => {
                const key = comboKey(v1.name, v2.name);
                const d = comboData[key] || { sku: '', quantity: 0, absolute_price: 0, absolute_cost: 0 };
                $tbody.appendChild(buildComboRow(v1.name, v2.name, d));
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

function beforeSubmit() {
    $optionsRoot.querySelectorAll('input[data-gen]').forEach(el => el.remove());

    if (mode === 'none' || opt1Values.length === 0) return;

    saveTableToState();

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

        let ci = 0;
        opt1Values.forEach(v1 => {
            opt2Values.forEach(v2 => {
                const key = comboKey(v1.name, v2.name);
                const d = comboData[key] || {};
                addHidden('combinations[' + ci + '][opt1]', v1.name);
                addHidden('combinations[' + ci + '][opt2]', v2.name);
                addHidden('combinations[' + ci + '][sku]', d.sku || '');
                addHidden('combinations[' + ci + '][quantity]', d.quantity || 0);
                addHidden('combinations[' + ci + '][absolute_price]', d.absolute_price || 0);
                addHidden('combinations[' + ci + '][absolute_cost]', d.absolute_cost || 0);
                ci++;
            });
        });
    } else {
        addHidden('option_name', opt1Name);

        opt1Values.forEach((v, idx) => {
            addHidden('values[' + idx + '][name]', v.name);
            addHidden('values[' + idx + '][sku]', v.sku || '');
            addHidden('values[' + idx + '][quantity]', v.quantity || 0);
            addHidden('values[' + idx + '][absolute_price]', v.absolute_price || 0);
            addHidden('values[' + idx + '][absolute_cost]', v.absolute_cost || 0);
            if (v.option_value_id) {
                addHidden('values[' + idx + '][option_value_id]', v.option_value_id);
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
    opt2Values = [];
    renderOpt2List();
    $opt2Name.value = '';
    const newData = {};
    opt1Values.forEach(v1 => {
        const key = comboKey(v1.name, null);
        const found = Object.keys(comboData).find(k => k.startsWith(v1.name + '|'));
        if (found) newData[key] = comboData[found];
    });
    comboData = newData;
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
    form.addEventListener('submit', () => {
        recalcProductQty();
        beforeSubmit();
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
                const key = comboKey(c.opt1_name, c.opt2_name);
                comboData[key] = {
                    sku: c.sku || '',
                    quantity: c.quantity || 0,
                    absolute_price: parseFloat(c.absolute_price) || 0,
                    absolute_cost: parseFloat(c.absolute_cost) || 0,
                };
            });
        }
        setMode('two');
    } else {
        setMode('one');
    }
} else {
    recalcProductQty();
}
</script>
@endpush
