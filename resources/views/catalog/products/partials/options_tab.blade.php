<form method="POST" action="{{ route('products.options.update', $product->product_id) }}">
    @csrf

    <div id="options-root"></div>

    <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
        <button class="btn secondary" type="button" id="add-option-btn">Add Option</button>
        <button class="btn" type="submit">Save Options</button>
    </div>
</form>

<template id="tpl-option">
    <div class="card" style="margin-top:14px; padding:14px; border:1px solid rgba(255,255,255,0.08);">
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
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

        <div class="hint" style="margin-top:8px;">For select/radio/checkbox: add values below. For text/date/time/file: the value is stored in OpenCart's product_option.value.</div>

        <div class="js-values" style="margin-top:10px;"></div>

        <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
            <button class="btn secondary" type="button" data-action="add-value">Add Value</button>
        </div>
    </div>
</template>

<template id="tpl-value-row">
    <div class="card" style="margin-top:10px; padding:12px; border:1px solid rgba(255,255,255,0.08);">
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <div style="min-width:240px; flex:1;">
                <label class="required">Value</label>
                <select class="input js-value-select"></select>
                <input type="hidden" class="js-value-id" />
            </div>
            <div style="width:180px;">
                <label>SKU</label>
                <input class="input js-row-sku" placeholder="optional">
            </div>
            <div style="width:140px;">
                <label>Quantity</label>
                <input class="input js-row-qty" type="number" value="0">
            </div>
            <div style="width:120px;">
                <label>Price Prefix</label>
                <select class="input js-row-price-prefix">
                    <option value="+">+</option>
                    <option value="-">-</option>
                </select>
            </div>
            <div style="width:140px;">
                <label>Price</label>
                <input class="input js-row-price" type="number" step="0.0001" value="0">
            </div>
            <div style="width:120px;">
                    <option value="+">+</option>
                    <option value="-">-</option>
                </select>
            </div>
            <div style="width:140px;">
            </div>
            <div style="width:140px;">
            </div>
            <div style="width:140px;">
            </div>
            <div style="width:140px; margin-left:auto;">
                <button class="btn danger" type="button" data-action="remove-value" style="width:100%;">Remove</button>
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
  row.querySelector('.js-row-price-prefix').value = seed?.price_prefix ?? '+';
  row.querySelector('.js-row-price').value = seed?.price ?? 0;

  row.querySelector('[data-action="remove-value"]').addEventListener('click', () => row.remove());

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

    const existing = (seed?.values ?? []).map(v => ({
      option_value_id: v.option_value_id,
      sku: v.sku ?? '',
      quantity: v.quantity ?? 0,
      price_prefix: v.price_prefix ?? '+',
      price: v.price ?? 0,
    }));

    if(existing.length === 0){
      valuesRoot.appendChild(buildValueRow(valuesList, null));
    } else {
      for(const ev of existing){
        valuesRoot.appendChild(buildValueRow(valuesList, ev));
      }
    }

    block.querySelector('[data-action="add-value"]').onclick = () => {
      valuesRoot.appendChild(buildValueRow(valuesList, null));
    };
  }

  sel.addEventListener('change', refreshValues);

  block.querySelector('[data-action="remove-option"]').addEventListener('click', () => block.remove());

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
      const pp = r.querySelector('.js-row-price-prefix').value || '+';
      const price = r.querySelector('.js-row-price').value || '0';

      const add = (n,v) => {
        const el = document.createElement('input');
        el.type='hidden'; el.name=n; el.value=v; el.dataset.gen='1';
        root.appendChild(el);
      };

      add(`options[${idx}][values][${vIdx}][option_value_id]`, valId);
      add(`options[${idx}][values][${vIdx}][sku]`, sku);
      add(`options[${idx}][values][${vIdx}][quantity]`, qty);
      add(`options[${idx}][values][${vIdx}][price_prefix]`, pp);
      add(`options[${idx}][values][${vIdx}][price]`, price);

      vIdx++;
    }

    idx++;
  }
}

// init
const root = document.getElementById('options-root');
if(root){
  EXISTING.forEach(seed => root.appendChild(buildOptionBlock(seed)));
  document.getElementById('add-option-btn')?.addEventListener('click', () => {
    root.appendChild(buildOptionBlock(null));
  });

  // hook submit only for this tab's form
  const form = root.closest('form');
  form?.addEventListener('submit', beforeSubmit);
}
</script>
@endpush