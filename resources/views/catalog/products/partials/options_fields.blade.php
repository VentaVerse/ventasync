
<div id="options-error" class="fm-vars__errors d-none" role="alert"></div>

<div class="fm-vars" id="options-root">
    <input type="hidden" name="_options_format" value="absolute">

    <div class="fm-vars__row">
        <div id="option1-group" class="d-none fm-vars__col">
            <div class="fm-vars__card">
                <div class="fm-vars__head">
                    <div class="fm-field">
                        <label class="fm-label" for="opt1-name-input">
                            Variation 1 <span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span>
                        </label>
                        <input class="x-input" id="opt1-name-input" placeholder="Colour, cable length" autocomplete="off">
                    </div>
                    <x-ui.button type="button" variant="danger" size="sm" id="remove-opt1-btn">Remove</x-ui.button>
                </div>

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

        <div id="option2-group" class="d-none fm-vars__col">
            <div class="fm-vars__card">
                <div class="fm-vars__head">
                    <div class="fm-field">
                        <label class="fm-label" for="opt2-name-input">
                            Variation 2 <span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span>
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

    <div id="data-table-wrap" class="d-none">
        @if(isset($product))
            <div class="fm-vars__lock">
                <p class="fm-vars__lock-note" id="vars-cost-lock-note">Cost and Additional are set when a purchase order is received.</p>
                <x-ui.button type="button" size="sm" id="vars-cost-override-btn">Edit by hand</x-ui.button>
            </div>
        @endif
        <p id="vars-cost-note" class="fm-vars__note d-none"></p>
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

@php
    $typedBack = ($errors ?? null)?->any() && (old('option_name') !== null || old('option1_name') !== null);
    if ($typedBack && old('option1_name') !== null) {
        $seedOption = (object) ['option_name' => old('option1_name')];
        $seedOption2 = (object) ['option_name' => old('option2_name')];
        $seedValues = collect((array) old('option1_values', []))->map(fn ($n) => (object) ['name' => (string) $n])->values();
        $seedValues2 = collect((array) old('option2_values', []))->map(fn ($n) => (object) ['name' => (string) $n])->values();
        $seedCombos = collect((array) old('combinations', []))->map(fn ($c) => (object) [
            'opt1_name' => (string) ($c['opt1'] ?? ''),
            'opt2_name' => (string) ($c['opt2'] ?? ''),
            'sku' => (string) ($c['sku'] ?? ''),
            'image' => (string) ($c['image'] ?? ''),
            'status' => (int) ($c['status'] ?? 1),
            'quantity' => $c['quantity'] ?? 0,
            'absolute_price' => $c['absolute_price'] ?? 0,
            'cost_amount' => $c['cost_amount'] ?? 0,
            'cost_additional' => $c['cost_additional'] ?? 0,
        ])->values();
    } elseif ($typedBack) {
        $seedOption = (object) ['option_name' => old('option_name')];
        $seedOption2 = null;
        $seedValues = collect((array) old('values', []))->map(fn ($v) => (object) [
            'name' => (string) ($v['name'] ?? ''),
            'option_value_id' => $v['option_value_id'] ?? null,
            'sku' => (string) ($v['sku'] ?? ''),
            'image' => (string) ($v['image'] ?? ''),
            'status' => (int) ($v['status'] ?? 1),
            'quantity' => $v['quantity'] ?? 0,
            'absolute_price' => $v['absolute_price'] ?? 0,
            'cost_amount' => $v['cost_amount'] ?? 0,
            'cost_additional' => $v['cost_additional'] ?? 0,
        ])->values();
        $seedValues2 = collect();
        $seedCombos = collect();
    } else {
        $seedOption = $existingOptions->first() ?? null;
        $seedOption2 = $existingOptions->count() > 1 ? $existingOptions->get(1) : null;
        $seedValues = $seedOption ? ($seedOption->values ?? []) : [];
        $seedValues2 = $seedOption2 ? ($seedOption2->values ?? []) : [];
        $seedCombos = $existingCombinations ?? [];
    }
@endphp
const EXISTING_OPTION  = @json($seedOption);
const EXISTING_OPTION2 = @json($seedOption2);
const EXISTING_VALUES  = @json($seedValues);
const EXISTING_VALUES2 = @json($seedValues2);
const EXISTING_COMBOS  = @json($seedCombos);
let variationCostLocked = @json(isset($product));

let opt1Values = [];
let opt2Values = [];
let comboData  = {};
let mode       = 'none';

let renderedMode = 'none';

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

$tbody.addEventListener('input', function(e) {
    if (e.target.classList.contains('js-price') || e.target.classList.contains('js-cost') || e.target.classList.contains('js-addl')) {
        calcRowMetrics(e.target.closest('tr'));
    }
    if (e.target.classList.contains('js-qty')) {
        recalcProductQty();
    }
});

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

function setMode(newMode) {
    mode = newMode;
    $opt1Group.classList.toggle('d-none', mode === 'none');
    $opt2Group.classList.toggle('d-none', mode !== 'two');
    $opt2Wrapper.classList.toggle('d-none', mode !== 'one');
    $addOptWrapper.classList.toggle('d-none', mode !== 'none');
    $tableWrap.classList.toggle('d-none', mode === 'none');
    $addRowBtn.classList.toggle('d-none', mode !== 'one');
    $opt1ValSection.classList.toggle('d-none', mode !== 'two');
    if (mode === 'two') renderOpt1List();
    renderTable();
    recalcProductQty();
}

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
    opt1Values.push({ name, sku: '', image: '', status: 1, quantity: 0, absolute_price: 0, cost_amount: 0, cost_additional: parentCostFormula().add });
    renderOpt1List();
    renderTable();
    recalcProductQty();
    const inputs = $opt1ValList.querySelectorAll('.js-opt-val-name');
    const last = inputs[inputs.length - 1];
    if (last && !name) last.focus();
}

function seedFirstColumnFromOneAxis() {
    if (Object.keys(comboData).length > 0) return;

    opt1Values.forEach((v1, i1) => {
        comboData[comboKey(i1, 0)] = {
            sku: v1.sku || '',
            image: v1.image || '',
            status: v1.status === undefined ? 1 : v1.status,
            quantity: v1.quantity || 0,
            absolute_price: v1.absolute_price || 0,
            cost_amount: v1.cost_amount || 0,
            cost_additional: rowAdditional(v1),
        };
    });
}

function addOpt2Value(name) {
    name = (name || '').trim();

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

function saveTableToState() {
    if (renderedMode === 'one') {
        $tbody.querySelectorAll('tr').forEach((row, i) => {
            if (i >= opt1Values.length) return;
            const nameInput = row.querySelector('.js-value-name');
            if (nameInput) opt1Values[i].name = nameInput.value.trim();
            const skuInput = row.querySelector('.js-sku');
            if (skuInput) opt1Values[i].sku = skuInput.value;
            const imgInput = row.querySelector('.js-image');
            if (imgInput) opt1Values[i].image = imgInput.value;
            const onInput = row.querySelector('.js-enabled');
            if (onInput) opt1Values[i].status = onInput.checked ? 1 : 0;
            opt1Values[i].legacyBlankSku = !!(skuInput && skuInput.dataset.legacyBlank)
                && !(skuInput.value || '').trim();
            const qtyInput = row.querySelector('.js-qty');
            if (qtyInput) opt1Values[i].quantity = parseInt(qtyInput.value || '0', 10);
            const priceInput = row.querySelector('.js-price');
            if (priceInput) opt1Values[i].absolute_price = parseFloat(priceInput.value || '0');
            const costInput = row.querySelector('.js-cost');
            if (costInput) opt1Values[i].cost_amount = parseFloat(costInput.value || '0');
            const addlInput = row.querySelector('.js-addl');
            if (addlInput) opt1Values[i].cost_additional = parseFloat(addlInput.value || '0');
        });
    } else if (renderedMode === 'two') {
        $tbody.querySelectorAll('tr').forEach(row => {
            const key = row.dataset.comboKey;
            if (!key) return;
            const comboSku = row.querySelector('.js-sku');
            comboData[key] = {
                sku: comboSku.value || '',
                legacyBlankSku: !!comboSku.dataset.legacyBlank && !(comboSku.value || '').trim(),
                image: (row.querySelector('.js-image') || {}).value || '',
                status: (row.querySelector('.js-enabled') || {}).checked ? 1 : 0,
                quantity: parseInt(row.querySelector('.js-qty').value || '0', 10),
                absolute_price: parseFloat(row.querySelector('.js-price').value || '0'),
                cost_amount: parseFloat(row.querySelector('.js-cost').value || '0'),
                cost_additional: parseFloat((row.querySelector('.js-addl') || {}).value || '0'),
            };
        });
    }
}

function parentCostFormula() {
    const num = (id) => {
        const el = document.getElementById(id);
        return el ? (parseFloat(el.value) || 0) : 0;
    };
    return { pct: num('cost_percentage'), add: num('cost_additional') };
}

function costFormulaApplies() {
    const f = parentCostFormula();
    return f.pct !== 0 || f.add !== 0;
}

function rowAdditional(v) {
    const raw = v ? v.cost_additional : undefined;
    return (raw === undefined || raw === null || raw === '') ? parentCostFormula().add : (parseFloat(raw) || 0);
}

function variationUnitCost(amount, price, add) {
    const f = parentCostFormula();
    return amount + (f.pct / 100 * price) + (add || 0);
}

function parentUnitCost() {
    const num = (id) => {
        const el = document.getElementById(id);
        return el ? (parseFloat(el.value) || 0) : 0;
    };
    const f = parentCostFormula();
    return num('cost_amount') + (f.pct / 100 * num('price')) + f.add;
}

function calcRowMetrics(row) {
    const priceInput = row.querySelector('.js-price');
    const costInput  = row.querySelector('.js-cost');
    const addlInput  = row.querySelector('.js-addl');
    const price  = parseFloat(priceInput ? priceInput.value : 0) || 0;
    const amount = parseFloat(costInput ? costInput.value : 0) || 0;
    const add    = parseFloat(addlInput ? addlInput.value : 0) || 0;
    const own    = amount > 0;
    const cost   = own ? variationUnitCost(amount, price, add) : parentUnitCost();
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

    const unitEl = row.querySelector('.js-unitcost');
    if (unitEl) {
        const applies = own ? (parentCostFormula().pct !== 0 || add !== 0) : cost > 0;
        unitEl.classList.toggle('d-none', !applies);
        clearChildren(unitEl);
        if (applies) {
            unitEl.appendChild(document.createTextNode('= ' + cost.toFixed(2)));
            if (!own) unitEl.appendChild(createEl('span', { className: 'fm-vars__unit-why' }, 'the product\u2019s'));
        }
    }
}

function buildAdditionalCell(add, rowName, idx) {
    const td = document.createElement('td');
    const input = createInput('js-addl', 'number', add || 0, {
        step: '0.01',
        'aria-label': labelFor('Additional cost', rowName, idx),
    });
    applyCostLock(input);
    td.appendChild(input);
    return td;
}

function applyCostLock(input) {
    if (variationCostLocked) {
        input.setAttribute('readonly', 'readonly');
        input.setAttribute('tabindex', '-1');
    } else {
        input.removeAttribute('readonly');
        input.removeAttribute('tabindex');
    }
}

(function bindVariationCostLock() {
    const btn = document.getElementById('vars-cost-override-btn');
    const note = document.getElementById('vars-cost-lock-note');
    if (!btn) return;

    const applyAll = () => $tbody.querySelectorAll('.js-cost, .js-addl').forEach(applyCostLock);

    btn.addEventListener('click', () => {
        if (variationCostLocked) {
            window.confirmModal('Variation cost is normally set when a purchase order is received. Edit it by hand anyway?').then((ok) => {
                if (!ok) return;
                variationCostLocked = false;
                applyAll();
                if (note) note.textContent = 'Editing by hand. Saving will overwrite the purchase-order figures.';
                btn.textContent = 'Lock';
            });
        } else {
            variationCostLocked = true;
            applyAll();
            if (note) note.textContent = 'Cost and Additional are set when a purchase order is received.';
            btn.textContent = 'Edit by hand';
        }
    });
})();

let costCellSeq = 0;
function buildCostCell(amount, rowName, idx) {
    const td = document.createElement('td');
    const unitId = 'vcost-' + (costCellSeq++);

    const input = createInput('js-cost', 'number', amount || 0, {
        step: '0.01',
        'aria-label': labelFor('Cost', rowName, idx),
        'aria-describedby': unitId,
    });
    applyCostLock(input);
    td.appendChild(input);

    const unit = createEl('span', { className: 'fm-vars__unit js-unitcost d-none', id: unitId });
    td.appendChild(unit);

    return td;
}

function bindRowEvents(tr) {
    const priceEl = tr.querySelector('.js-price');
    const costEl  = tr.querySelector('.js-cost');
    const addlEl  = tr.querySelector('.js-addl');
    const qtyEl   = tr.querySelector('.js-qty');
    const recalcPrice = () => calcRowMetrics(tr);
    const recalcQty = () => recalcProductQty();
    if (priceEl) { priceEl.addEventListener('input', recalcPrice); priceEl.addEventListener('change', recalcPrice); }
    if (costEl)  { costEl.addEventListener('input', recalcPrice); costEl.addEventListener('change', recalcPrice); }
    if (addlEl)  { addlEl.addEventListener('input', recalcPrice); addlEl.addEventListener('change', recalcPrice); }
    if (qtyEl)   { qtyEl.addEventListener('input', recalcQty); qtyEl.addEventListener('change', recalcQty); }
}

function buildHeaderRow(is2) {
    const tr = document.createElement('tr');

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
    tr.appendChild(th('fm-vcol-addl', 'Additional'));
    tr.appendChild(th('fm-vcol-img', 'Image'));
    tr.appendChild(th('fm-vcol-on', 'On'));
    const thAct = th('fm-vcol-act', '');
    thAct.appendChild(createEl('span', { className: 'x-sr' }, 'Row actions'));
    tr.appendChild(thAct);
    return tr;
}

function labelFor(column, rowName, idx) {
    const who = (rowName && String(rowName).trim())
        ? String(rowName).trim()
        : ('value ' + ((idx || 0) + 1));

    return column + ' for ' + who;
}

function buildStatusCell(enabled, rowName, idx) {
    const td = createEl('td', { className: 'fm-vcol-on' });

    const label = createEl('label', { className: 'fm-switch fm-switch--sm' });

    const input = createEl('input', {
        type: 'checkbox',
        className: 'fm-switch__input js-enabled',
        'aria-label': labelFor('Offered for sale', rowName, idx),
    });
    input.checked = enabled !== false && enabled !== 0 && enabled !== '0';

    label.appendChild(input);
    label.appendChild(createEl('span', { className: 'fm-switch__track' }));
    td.appendChild(label);

    return td;
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

    const rowName = [v1Name, v2Name].filter(Boolean).join(', ');

    const tdSku   = document.createElement('td');
    const skuCombo = createInput('js-sku', 'text', data.sku,
        { 'aria-label': labelFor('SKU', rowName, i1) });
    if (data.legacyBlankSku) skuCombo.dataset.legacyBlank = '1';
    tdSku.appendChild(skuCombo);
    tr.appendChild(tdSku);

    const tdQty   = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', data.quantity,
        { 'aria-label': labelFor('Quantity', rowName, i1) }));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', data.absolute_price,
        { step: '0.01', 'aria-label': labelFor('Price', rowName, i1) }));
    tr.appendChild(tdPrice);

    tr.appendChild(buildCostCell(data.cost_amount, rowName, i1));
    tr.appendChild(buildAdditionalCell(rowAdditional(data), rowName, i1));

    tr.appendChild(buildImageCell(data.image, rowName, i1));
    tr.appendChild(buildStatusCell(data.status, rowName, i1));

    tr.appendChild(document.createElement('td'));

    bindRowEvents(tr);
    calcRowMetrics(tr);
    return tr;
}

function buildOneOptRow(v, idx) {
    const tr = document.createElement('tr');
    tr.dataset.rowIdx = idx;

    const tdName = document.createElement('td');
    const nameInput = createInput('js-value-name', 'text', v.name, {
        placeholder: 'Value name',
        'aria-label': labelFor('Variation value name', v.name, idx),
    });
    tdName.appendChild(nameInput);
    tr.appendChild(tdName);

    const tdSku = document.createElement('td');
    const skuOne = createInput('js-sku', 'text', v.sku || '',
        { 'aria-label': labelFor('SKU', v.name, idx) });
    if (v.legacyBlankSku) skuOne.dataset.legacyBlank = '1';
    tdSku.appendChild(skuOne);
    tr.appendChild(tdSku);

    const tdQty = document.createElement('td');
    tdQty.appendChild(createInput('js-qty', 'number', v.quantity || 0,
        { 'aria-label': labelFor('Quantity', v.name, idx) }));
    tr.appendChild(tdQty);

    const tdPrice = document.createElement('td');
    tdPrice.appendChild(createInput('js-price', 'number', v.absolute_price || 0,
        { step: '0.01', 'aria-label': labelFor('Price', v.name, idx) }));
    tr.appendChild(tdPrice);

    tr.appendChild(buildCostCell(v.cost_amount, v.name, idx));
    tr.appendChild(buildAdditionalCell(rowAdditional(v), v.name, idx));

    tr.appendChild(buildImageCell(v.image, v.name, idx));
    tr.appendChild(buildStatusCell(v.status, v.name, idx));

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
                const d = comboData[key] || { sku: '', image: '', status: 1, quantity: 0, absolute_price: 0, cost_amount: 0 };
                $tbody.appendChild(buildComboRow(i1, i2, v1.name, v2.name, d));
            });
        });
    } else {
        opt1Values.forEach((v, idx) => {
            $tbody.appendChild(buildOneOptRow(v, idx));
        });
    }

    renderedMode = is2 ? 'two' : 'one';
}

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

        const comboRows = $tbody.querySelectorAll('tr[data-combo-key]');
        let ci = 0;
        comboRows.forEach(row => {
            const parts = row.dataset.comboKey.split('|').map(Number);
            addHidden('combinations[' + ci + '][opt1]', opt1Values[parts[0]] ? opt1Values[parts[0]].name : '');
            addHidden('combinations[' + ci + '][opt2]', opt2Values[parts[1]] ? opt2Values[parts[1]].name : '');
            addHidden('combinations[' + ci + '][sku]', (row.querySelector('.js-sku') || {}).value || '');
            addHidden('combinations[' + ci + '][image]', (row.querySelector('.js-image') || {}).value || '');
            addHidden('combinations[' + ci + '][status]', (row.querySelector('.js-enabled') || {}).checked ? 1 : 0);
            addHidden('combinations[' + ci + '][quantity]', (row.querySelector('.js-qty') || {}).value || 0);
            addHidden('combinations[' + ci + '][absolute_price]', (row.querySelector('.js-price') || {}).value || 0);
            addHidden('combinations[' + ci + '][cost_amount]', (row.querySelector('.js-cost') || {}).value || 0);
            addHidden('combinations[' + ci + '][cost_additional]', (row.querySelector('.js-addl') || {}).value || 0);
            ci++;
        });
    } else {
        addHidden('option_name', opt1Name);

        const rows = $tbody.querySelectorAll('tr');
        rows.forEach((row, idx) => {
            const nameInput = row.querySelector('.js-value-name');
            const name = nameInput ? nameInput.value.trim() : '';
            if (!name) return;

            addHidden('values[' + idx + '][name]', name);
            addHidden('values[' + idx + '][sku]', (row.querySelector('.js-sku') || {}).value || '');
            addHidden('values[' + idx + '][image]', (row.querySelector('.js-image') || {}).value || '');
            addHidden('values[' + idx + '][status]', (row.querySelector('.js-enabled') || {}).checked ? 1 : 0);
            addHidden('values[' + idx + '][quantity]', (row.querySelector('.js-qty') || {}).value || 0);
            addHidden('values[' + idx + '][absolute_price]', (row.querySelector('.js-price') || {}).value || 0);
            addHidden('values[' + idx + '][cost_amount]', (row.querySelector('.js-cost') || {}).value || 0);
            addHidden('values[' + idx + '][cost_additional]', (row.querySelector('.js-addl') || {}).value || 0);

            if (idx < opt1Values.length && opt1Values[idx].option_value_id) {
                addHidden('values[' + idx + '][option_value_id]', opt1Values[idx].option_value_id);
            }
        });
    }
}

document.getElementById('add-option-btn').addEventListener('click', () => {
    setMode('one');
    $opt1Name.focus();
});

document.getElementById('remove-opt1-btn').addEventListener('click', () => {
    const wipe = () => {
        opt1Values = [];
        opt2Values = [];
        comboData = {};
        renderOpt1List();
        renderOpt2List();
        $opt1Name.value = '';
        $opt2Name.value = '';
        setMode('none');
    };

    const rows = $tbody.querySelectorAll('tr').length;

    if (rows === 0 || typeof window.confirmModal !== 'function') {
        wipe();
        return;
    }

    const label = ($opt1Name.value || '').trim() || 'this variation';

    window.confirmModal(
        'Remove ' + label + '? Its ' + rows + (rows === 1 ? ' row' : ' rows')
        + ', with their SKUs, stock, prices and images, are deleted when you save.',
        document.getElementById('remove-opt1-btn')
    ).then((ok) => { if (ok) wipe(); });
});

document.getElementById('add-opt2-btn').addEventListener('click', () => {
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
    const dropped = Math.max(0, $tbody.querySelectorAll('tr').length - opt1Values.length);

    if (dropped > 0 && typeof window.confirmModal === 'function') {
        const label = ($opt2Name.value || '').trim() || 'the second variation';

        window.confirmModal(
            'Remove ' + label + '? The first column keeps its SKUs, stock and prices; '
            + dropped + (dropped === 1 ? ' other combination is' : ' other combinations are')
            + ' deleted when you save.',
            document.getElementById('remove-opt2-btn')
        ).then((ok) => { if (ok) removeSecondAxis(); });
        return;
    }

    removeSecondAxis();
});

function removeSecondAxis() {
    saveTableToState();
    opt1Values.forEach((v1, i1) => {
        const first = comboData[comboKey(i1, 0)];
        if (!first) return;

        const firstIsEmpty = !first.sku
            && !Number(first.quantity)
            && !Number(first.absolute_price)
            && !Number(first.cost_amount);

        const valueHasData = !!v1.sku
            || !!Number(v1.quantity)
            || !!Number(v1.absolute_price)
            || !!Number(v1.cost_amount);

        if (firstIsEmpty && valueHasData) return;

        v1.sku = first.sku || '';
        v1.image = first.image || '';
        v1.status = first.status === undefined ? 1 : first.status;
        v1.quantity = first.quantity || 0;
        v1.absolute_price = first.absolute_price || 0;
        v1.cost_amount = first.cost_amount || 0;
    });
    opt2Values = [];
    renderOpt2List();
    $opt2Name.value = '';
    comboData = {};
    setMode('one');
}

$addRowBtn.addEventListener('click', () => {
    saveTableToState();
    opt1Values.push({ name: '', sku: '', image: '', status: 1, quantity: 0, absolute_price: 0, cost_amount: 0 });
    renderTable();
    const rows = $tbody.querySelectorAll('tr');
    const lastRow = rows[rows.length - 1];
    if (lastRow) {
        const nameInput = lastRow.querySelector('.js-value-name');
        if (nameInput) nameInput.focus();
    }
    recalcProductQty();
});

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

    function showOptNotice(message) {
        $optError.textContent = '';
        const div = createEl('div', { className: 'fm-note--soft' }, message);
        $optError.appendChild(div);
        $optError.classList.remove('d-none');
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

    function submitPastThisListener() {
        beforeSubmit();

        if (form.requestSubmit) {
            form._optionsValidated = true;

            setTimeout(() => form.requestSubmit(), 0);
            return;
        }

        form.submit();
    }

    form.addEventListener('submit', (e) => {
        if (form._optionsValidated) {
            form._optionsValidated = false;
            return;
        }

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

        const seen1 = {};
        opt1Values.forEach(v => {
            const lower = (v.name || '').trim().toLowerCase();
            if (lower && seen1[lower]) {
                errors.push('Duplicate value name "' + v.name.trim() + '" in Variation 1.');
            }
            if (lower) seen1[lower] = true;
        });

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
        let legacyBlankCount = 0;
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
                if (skuInput.dataset.legacyBlank) {
                    legacyBlankCount++;
                } else {
                    skuInput.classList.add('input-error');
                    if (!firstBadInput) firstBadInput = skuInput;
                }
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
            const missing = $tbody.querySelectorAll('.js-sku.input-error').length;
            errors.push(missing === 1
                ? 'One new variation needs an SKU.'
                : missing + ' new variations need an SKU.');
        }

        if (errors.length > 0) {
            showOptError(errors);
            if (firstBadInput) firstBadInput.focus();
            return;
        }

        if (legacyBlankCount > 0) {
            showOptNotice(legacyBlankCount === 1
                ? 'One variation has no SKU. It will not map to a marketplace listing.'
                : legacyBlankCount + ' variations have no SKU. They will not map to marketplace listings.');
        }

        if (allSkus.length === 0) {
            submitPastThisListener();
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
                submitPastThisListener();
            })
            .catch(() => {
                submitPastThisListener();
            });
    });
}

if (EXISTING_OPTION) {
    EXISTING_VALUES.forEach(v => {
        opt1Values.push({
            name: v.option_value_name || v.name,
            option_value_id: v.option_value_id,
            sku: v.sku || '',
            legacyBlankSku: !String(v.sku || '').trim(),
            status: v.status === undefined ? 1 : Number(v.status),
            image: v.image || '',
            quantity: v.quantity || 0,
            absolute_price: parseFloat(v.absolute_price) || 0,
            cost_amount: parseFloat(v.cost_amount) || 0,
            cost_additional: parseFloat(v.cost_additional) || 0,
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
                        legacyBlankSku: !String(c.sku || '').trim(),
                        status: c.status === undefined ? 1 : Number(c.status),
                        image: c.image || '',
                        quantity: c.quantity || 0,
                        absolute_price: parseFloat(c.absolute_price) || 0,
                        cost_amount: parseFloat(c.cost_amount) || 0,
                        cost_additional: parseFloat(c.cost_additional) || 0,
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

function refreshCostFormula() {
    const note = document.getElementById('vars-cost-note');
    const { pct, add } = parentCostFormula();
    const applies = pct !== 0 || add !== 0;

    if (note) {
        note.classList.toggle('d-none', !applies);
        if (applies) {
            const parts = [];
            if (pct !== 0) parts.push('this product\u2019s ' + pct + '% cost share');
            if (add !== 0) parts.push('its own additional cost, which starts at the product\u2019s ' + add);
            note.textContent = 'Cost is what you pay per unit. Every variation also carries '
                + parts.join(' and ')
                + ', so the figure under each amount is what the unit really costs. A variation with no cost of its own costs what the product costs.';
        } else {
            note.textContent = '';
        }
    }

    $tbody.querySelectorAll('tr').forEach(calcRowMetrics);
}

['cost_percentage', 'cost_additional'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', refreshCostFormula);
    el.addEventListener('change', refreshCostFormula);
});

refreshCostFormula();

@if($errors->has('option_sku'))
(function() {
    const errorBySku = @json(
        collect($errors->get('option_sku'))->mapWithKeys(function($msg) {
            if (preg_match('/SKU "([^"]+)"/', $msg, $m)) return [strtolower($m[1]) => $msg];
            return [];
        })
    );
    if (!Object.keys(errorBySku).length) return;
    const skuInputs = document.querySelectorAll('#data-table .js-sku');
    skuInputs.forEach(input => {
        const msg = errorBySku[input.value.trim().toLowerCase()];
        if (msg) {
            input.classList.add('input-error');
            const errDiv = document.createElement('div');
            errDiv.className = 'fm-error';
            errDiv.textContent = msg;
            input.parentNode.appendChild(errDiv);
        }
    });
})();
@endif
</script>
@endpush
