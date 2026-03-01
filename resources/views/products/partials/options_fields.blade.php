@if(session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="alert error">{{ session('error') }}</div>
@endif

{{--
  NOTE:
  This partial is included by both the Product Create and Product Edit forms.
  Do NOT open a nested <form> here (it breaks HTML and prevents Create from rendering because it has no product id yet).
  The parent form (products.store / products.update) will submit these option fields.
--}}

<div id="options-root"></div>

<div class="d-flex gap-10 items-center mt-12">
    <button class="btn secondary" type="button" id="add-option-btn">Add Option</button>
</div>

<template id="tpl-option">
    <div class="card mt-16" style="padding:14px;">
        <div class="d-flex gap-10 flex-wrap" style="align-items:end;">
            <div style="min-width:260px; flex:1;">
                <label class="required">Option</label>
                <select class="input js-option-select"></select>
                <input type="hidden" class="js-option-id" />
            </div>
            <div style="width:180px;">
                <label>Required</label>
                <select class="input js-option-required">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>
            <div style="width:140px; margin-left:auto;">
                <button class="btn danger" type="button" data-action="remove-option" style="width:100%;">Remove</button>
            </div>
        </div>

        <div class="hint mt-12">For select/radio/checkbox: add values below. For text/date/time/file: the value is stored in OpenCart's product_option.value.</div>

        <div class="js-values mt-12"></div>

        <div class="d-flex gap-10 items-center mt-12">
            <button class="btn secondary" type="button" data-action="add-value">Add Value</button>
        </div>
    </div>
</template>

<template id="tpl-value-row">
    <div class="card mt-8" style="padding:0; overflow:hidden;">
        <div class="d-flex" style="align-items:stretch;">
            {{-- Left: Option name --}}
            <div class="js-row-option-label" style="width:120px; flex-shrink:0; background:var(--surface-alt); display:flex; align-items:center; justify-content:center; padding:8px; font-weight:600; font-size:13px; border-right:1px solid var(--border-light); text-align:center;">
                —
            </div>
            {{-- Right: Fields grid --}}
            <div style="flex:1; min-width:0; padding:10px;">
                <div style="display:grid; grid-template-columns:repeat(8, 1fr); gap:8px; align-items:end;">
                    <div>
                        <label class="required">Value</label>
                        <select class="input js-value-select"></select>
                        <input type="hidden" class="js-value-id" />
                    </div>
                    <div>
                        <label>SKU</label>
                        <input class="input js-row-sku" placeholder="optional">
                    </div>
                    <div>
                        <label>Quantity</label>
                        <input class="input js-row-qty" type="number" value="0">
                    </div>
                    <div>
                        <label>Subtract</label>
                        <label class="toggle-switch" style="display:block; margin-top:6px;">
                            <input type="checkbox" class="js-row-subtract-toggle" checked>
                            <span class="toggle-slider"></span>
                        </label>
                        <select class="js-row-subtract" hidden><option value="1" selected>Yes</option><option value="0">No</option></select>
                    </div>
                    <div>
                        <label>Price</label>
                        <div class="d-flex gap-2">
                            <select class="input js-row-price-prefix" style="width:34px; flex-shrink:0; padding:6px 1px; text-align:center;"><option value="+">+</option><option value="-">-</option></select>
                            <input class="input js-row-price" type="number" step="0.01" value="0" style="flex:1; min-width:0;">
                        </div>
                    </div>
                    <div>
                        <label>Cost</label>
                        <div class="d-flex gap-2">
                            <select class="input js-row-cost-prefix" style="width:34px; flex-shrink:0; padding:6px 1px; text-align:center;"><option value="+">+</option><option value="-">-</option></select>
                            <input class="input js-row-cost" type="number" step="0.01" value="0" style="flex:1; min-width:0;">
                        </div>
                    </div>
                    <div>
                        <label>Weight</label>
                        <div class="d-flex gap-2">
                            <select class="input js-row-weight-prefix" style="width:34px; flex-shrink:0; padding:6px 1px; text-align:center;"><option value="+">+</option><option value="-">-</option></select>
                            <input class="input js-row-weight" type="number" step="0.01" value="0" style="flex:1; min-width:0;">
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <label>&nbsp;</label>
                        <button class="btn danger" type="button" data-action="remove-value" style="padding:6px 12px;">X</button>
                    </div>
                </div>
                {{-- Metrics row --}}
                <div style="display:grid; grid-template-columns:repeat(5, 1fr); gap:8px; margin-top:6px;">
                    <div>
                        <label style="font-size:11px; color:var(--text-muted);">Full Price</label>
                        <input class="input js-row-full-price" type="text" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600; text-align:right; font-size:12px; padding:4px 6px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted);">Full Cost</label>
                        <input class="input js-row-full-cost" type="text" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600; text-align:right; font-size:12px; padding:4px 6px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted);">Profit</label>
                        <input class="input js-row-profit" type="text" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600; text-align:right; font-size:12px; padding:4px 6px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted);">Margin</label>
                        <input class="input js-row-margin" type="text" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600; text-align:right; font-size:12px; padding:4px 6px;">
                    </div>
                    <div>
                        <label style="font-size:11px; color:var(--text-muted);">Markup</label>
                        <input class="input js-row-markup" type="text" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600; text-align:right; font-size:12px; padding:4px 6px;">
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>


@push('scripts')
<script>
const ALL_OPTIONS = @json($allOptions ?? []);
const EXISTING = @json($existingOptions ?? []);

function escHtml(s){
  return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function wordStartMatch(text, term){
  if(!text || !term) return false;
  const h = String(text).toLowerCase();
  const t = String(term).trim().toLowerCase();
  if(t === '') return false;
  return !!h.match(new RegExp('(^|[^a-z0-9])' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));
}

async function fetchValues(optionId){
  const res = await fetch(`/api/catalog/options/${optionId}/values`);
  if(!res.ok) return [];
  return await res.json();
}

function buildOptionSelect(selectEl, selectedId){
  selectEl.innerHTML = '';
  const opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = 'Select...';
  selectEl.appendChild(opt0);

  for(const o of ALL_OPTIONS){
    const opt = document.createElement('option');
    opt.value = o.option_id;
    opt.textContent = o.name;
    if(String(selectedId) === String(o.option_id)) opt.selected = true;
    selectEl.appendChild(opt);
  }
}

function buildValueSelect(selectEl, values, selectedId){
  selectEl.innerHTML = '';
  const opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = 'Select...';
  selectEl.appendChild(opt0);

  for(const v of values){
    const opt = document.createElement('option');
    opt.value = v.option_value_id;
    opt.textContent = v.name;
    if(String(selectedId) === String(v.option_value_id)) opt.selected = true;
    selectEl.appendChild(opt);
  }
}

function getOptionType(optionId){
  const o = ALL_OPTIONS.find(x => String(x.option_id) === String(optionId));
  return o ? o.type : '';
}

function buildValueRow(valuesList, seed){
  const tpl = document.getElementById('tpl-value-row');
  const row = tpl.content.firstElementChild.cloneNode(true);

  const select = row.querySelector('.js-value-select');
  const hidden = row.querySelector('.js-value-id');

  buildValueSelect(select, valuesList, seed?.option_value_id ?? '');

  select.addEventListener('change', () => {
    hidden.value = select.value || '';
  });

  hidden.value = seed?.option_value_id ?? '';

  row.querySelector('.js-row-sku').value = seed?.sku ?? '';
  row.querySelector('.js-row-qty').value = seed?.quantity ?? 0;

  // Toggle switch ↔ hidden select sync
  var subtractSelect = row.querySelector('.js-row-subtract');
  var subtractToggle = row.querySelector('.js-row-subtract-toggle');
  var subtractVal = String(seed?.subtract ?? 1);
  subtractSelect.value = subtractVal;
  subtractToggle.checked = subtractVal === '1';
  subtractToggle.addEventListener('change', function() { subtractSelect.value = this.checked ? '1' : '0'; });

  row.querySelector('.js-row-price-prefix').value = seed?.price_prefix ?? '+';
  row.querySelector('.js-row-price').value = seed?.price ?? 0;
  row.querySelector('.js-row-weight-prefix').value = seed?.weight_prefix ?? '+';
  row.querySelector('.js-row-weight').value = seed?.weight ?? 0;
  row.querySelector('.js-row-cost-prefix').value = seed?.cost_prefix ?? '+';
  row.querySelector('.js-row-cost').value = seed?.cost ?? 0;

  // Cost & profit fields
  var pricePrefixIn = row.querySelector('.js-row-price-prefix');
  var priceIn = row.querySelector('.js-row-price');
  var costPrefixIn = row.querySelector('.js-row-cost-prefix');
  var costIn = row.querySelector('.js-row-cost');
  var fullPriceOut = row.querySelector('.js-row-full-price');
  var fullCostOut = row.querySelector('.js-row-full-cost');
  var profitOut = row.querySelector('.js-row-profit');
  var marginOut = row.querySelector('.js-row-margin');
  var markupOut = row.querySelector('.js-row-markup');

  function calcOptionCost() {
    var basePrice = parseFloat((document.getElementById('product_price') || {}).value) || 0;
    var baseCost = parseFloat((document.getElementById('product_cost') || {}).value) || 0;
    var priceDelta = parseFloat(priceIn.value) || 0;
    var costDelta = parseFloat(costIn.value) || 0;

    var fullPrice = pricePrefixIn.value === '-' ? basePrice - priceDelta : basePrice + priceDelta;
    var fullCost = costPrefixIn.value === '-' ? baseCost - costDelta : baseCost + costDelta;
    var profit = fullPrice - fullCost;

    if (fullPriceOut) { fullPriceOut.value = fullPrice.toFixed(2); }
    if (fullCostOut) { fullCostOut.value = fullCost.toFixed(2); }
    if (profitOut) { profitOut.value = profit.toFixed(2); profitOut.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)'; }
    if (marginOut) { marginOut.value = fullPrice > 0 ? (profit / fullPrice * 100).toFixed(2) + '%' : '0%'; marginOut.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)'; }
    if (markupOut) { markupOut.value = fullCost > 0 ? (profit / fullCost * 100).toFixed(2) + '%' : '0%'; markupOut.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)'; }
  }
  [pricePrefixIn, priceIn, costPrefixIn, costIn].forEach(function(el) { el.addEventListener('input', calcOptionCost); el.addEventListener('change', calcOptionCost); });
  calcOptionCost();

  // Store calcOptionCost on the row so we can trigger it from base price/cost changes
  row._calcOptionCost = calcOptionCost;

  row.querySelector('[data-action="remove-value"]').addEventListener('click', () => {
    row.remove();
    recalcAndLockProductQuantity();
  });

  return row;
}

function buildOptionBlock(seed){
  const tpl = document.getElementById('tpl-option');
  const block = tpl.content.firstElementChild.cloneNode(true);

  const sel = block.querySelector('.js-option-select');
  const hid = block.querySelector('.js-option-id');
  const req = block.querySelector('.js-option-required');
  const valuesRoot = block.querySelector('.js-values');

  buildOptionSelect(sel, seed?.option_id ?? '');

  hid.value = seed?.option_id ?? '';
  req.value = String(seed?.required ?? 0);

  async function refreshValues(){
    valuesRoot.innerHTML = '';
    const optionId = sel.value;
    if(!optionId) return;

    hid.value = optionId;

    const type = getOptionType(optionId);
    if(!['select','radio','checkbox'].includes(type)){
      // non-valued options: nothing to render for values
      return;
    }

    const valuesList = await fetchValues(optionId);
    const optionName = sel.options[sel.selectedIndex]?.textContent || '—';

    const existing = (seed?.values ?? []).map(v => ({
      option_value_id: v.option_value_id,
      sku: v.sku ?? '',
      quantity: v.quantity ?? 0,
      subtract: (v.subtract ?? 1),
      price_prefix: v.price_prefix ?? '+',
      price: v.price ?? 0,
      weight_prefix: v.weight_prefix ?? '+',
      weight: v.weight ?? 0,
      cost_prefix: v.cost_prefix ?? '+',
      cost: v.cost ?? 0,
    }));

    function addRow(seed) {
      const row = buildValueRow(valuesList, seed);
      row.querySelector('.js-row-option-label').textContent = optionName;
      valuesRoot.appendChild(row);
      return row;
    }

    if(existing.length === 0){
      addRow(null);
    } else {
      for(const ev of existing){
        addRow(ev);
      }
    }

    block.querySelector('[data-action="add-value"]').onclick = () => {
      addRow(null);
      recalcAndLockProductQuantity();
    };

    // IMPORTANT:
    // On Edit, the option value rows are populated asynchronously (fetchValues).
    // If we calculate the total before these rows are rendered, Product Quantity
    // will momentarily show 0 on refresh even though saved values exist.
    // Recalculate after rendering to keep the UI consistent.
    recalcAndLockProductQuantity();
  }

  sel.addEventListener('change', refreshValues);

  block.querySelector('[data-action="remove-option"]').addEventListener('click', () => {
    block.remove();
    recalcAndLockProductQuantity();
  });

  refreshValues();

  return block;
}

function beforeSubmit(e){
  const root = document.getElementById('options-root');

  // Remove any previously generated hidden inputs (we generate fresh every submit)
  root.querySelectorAll('input[type="hidden"][data-gen="1"]').forEach(el => el.remove());

  const blocks = Array.from(root.querySelectorAll(':scope > .card'));
  let idx = 0;

  for(const b of blocks){
    const optionId = b.querySelector('.js-option-select').value || '';
    if(!optionId) continue;

    const required = b.querySelector('.js-option-required').value || '0';

    // Base fields
    const i1 = document.createElement('input');
    i1.type = 'hidden'; i1.name = `options[${idx}][option_id]`; i1.value = optionId; i1.dataset.gen = '1';
    root.appendChild(i1);

    const i2 = document.createElement('input');
    i2.type = 'hidden'; i2.name = `options[${idx}][required]`; i2.value = required; i2.dataset.gen = '1';
    root.appendChild(i2);

    // Values (if present)
    const rows = Array.from(b.querySelectorAll('.js-values .card'));
    let vIdx = 0;
    for(const r of rows){
      const valId = r.querySelector('.js-value-select')?.value || '';
      if(!valId) continue;

      const sku = r.querySelector('.js-row-sku').value || '';
      const qty = r.querySelector('.js-row-qty').value || '0';
      const subtract = r.querySelector('.js-row-subtract').value || '1';
      const pp = r.querySelector('.js-row-price-prefix').value || '+';
      const price = r.querySelector('.js-row-price').value || '0';
      const wp = r.querySelector('.js-row-weight-prefix').value || '+';
      const weight = r.querySelector('.js-row-weight').value || '0';

      const add = (n,v) => {
        const el = document.createElement('input');
        el.type='hidden'; el.name=n; el.value=v; el.dataset.gen='1';
        root.appendChild(el);
      };

      const cp = r.querySelector('.js-row-cost-prefix').value || '+';
      const cost = r.querySelector('.js-row-cost').value || '0';

      add(`options[${idx}][values][${vIdx}][option_value_id]`, valId);
      add(`options[${idx}][values][${vIdx}][sku]`, sku);
      add(`options[${idx}][values][${vIdx}][quantity]`, qty);
      add(`options[${idx}][values][${vIdx}][subtract]`, subtract);
      add(`options[${idx}][values][${vIdx}][price_prefix]`, pp);
      add(`options[${idx}][values][${vIdx}][price]`, price);
      add(`options[${idx}][values][${vIdx}][weight_prefix]`, wp);
      add(`options[${idx}][values][${vIdx}][weight]`, weight);
      add(`options[${idx}][values][${vIdx}][cost_prefix]`, cp);
      add(`options[${idx}][values][${vIdx}][cost]`, cost);

      vIdx++;
    }

    idx++;
  }
}

function recalcAndLockProductQuantity(){
  const root = document.getElementById('options-root');
  if(!root) return;

  const qtyInput = document.getElementById('product_quantity') || document.querySelector('input[name="quantity"]');
  if(!qtyInput) return;

  const hint = document.getElementById('qty_hint');

  const optionBlocks = Array.from(root.querySelectorAll(':scope > .card'));
  const hasAnyOptionBlock = optionBlocks.length > 0;

  // Sum option quantities in real-time.
  // Note: We intentionally do NOT require a value to be selected to count its quantity.
  // This allows the Product Quantity to update immediately while the user is still filling out rows.
  let total = 0;
  for(const b of optionBlocks){
    const optionId = b.querySelector('.js-option-select')?.value || '';
    if(!optionId) continue;
    const rows = Array.from(b.querySelectorAll('.js-values .card'));
    for(const r of rows){
      const v = parseInt(r.querySelector('.js-row-qty')?.value || '0', 10);
      if(!Number.isNaN(v) && v > 0) total += v;
    }
  }

  if(hasAnyOptionBlock){
    qtyInput.readOnly = true;
    qtyInput.value = String(total);
    qtyInput.setAttribute('aria-readonly', 'true');
    qtyInput.style.opacity = '0.85';
    qtyInput.title = 'Auto-calculated from option quantities';
    if(hint) hint.style.display = 'block';
  } else {
    qtyInput.readOnly = false;
    qtyInput.removeAttribute('aria-readonly');
    qtyInput.style.opacity = '';
    qtyInput.title = '';
    if(hint) hint.style.display = 'none';
  }
}

// init
const root = document.getElementById('options-root');
if(root){
  EXISTING.forEach(seed => root.appendChild(buildOptionBlock(seed)));
  document.getElementById('add-option-btn')?.addEventListener('click', () => {
    root.appendChild(buildOptionBlock(null));
    recalcAndLockProductQuantity();
  });

  // hook submit only for this tab's form
  const form = root.closest('form');
  form?.addEventListener('submit', (e) => {
    recalcAndLockProductQuantity();
    beforeSubmit(e);
  });

  // Live recalc when option qty changes
  root.addEventListener('input', (e) => {
    const t = e.target;
    if(t && (t.classList?.contains('js-row-qty') || t.classList?.contains('js-row-sku') || t.classList?.contains('js-row-price') || t.classList?.contains('js-row-weight'))){
      recalcAndLockProductQuantity();
    }
  });
  // Some browsers/UI interactions (spinner buttons, autofill) may not reliably emit 'input'
  // in all cases, so we also listen to 'change' and 'keyup' for quantity fields.
  root.addEventListener('change', (e) => {
    const t = e.target;
    if(t && (t.classList?.contains('js-option-select') || t.classList?.contains('js-value-select') || t.classList?.contains('js-row-qty'))){
      recalcAndLockProductQuantity();
    }
  });
  root.addEventListener('keyup', (e) => {
    const t = e.target;
    if(t && t.classList?.contains('js-row-qty')){
      recalcAndLockProductQuantity();
    }
  });

  // Recalculate all option profits when base product price or cost changes
  function recalcAllOptionCosts() {
    root.querySelectorAll('.js-values .card').forEach(function(row) {
      if (row._calcOptionCost) row._calcOptionCost();
    });
  }
  var basePriceEl = document.getElementById('product_price');
  var baseCostEl = document.getElementById('product_cost');
  if (basePriceEl) { basePriceEl.addEventListener('input', recalcAllOptionCosts); basePriceEl.addEventListener('change', recalcAllOptionCosts); }
  if (baseCostEl) { baseCostEl.addEventListener('input', recalcAllOptionCosts); baseCostEl.addEventListener('change', recalcAllOptionCosts); }

  // Also listen on cost breakdown inputs that update product_cost
  ['cost_amount', 'cost_percentage', 'cost_additional'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) { el.addEventListener('input', function() { setTimeout(recalcAllOptionCosts, 50); }); }
  });

  // Initial
  recalcAndLockProductQuantity();
}
</script>
@endpush