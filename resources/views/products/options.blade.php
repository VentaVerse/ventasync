@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <div>
            <h2>Product Options: {{ $product->name }}</h2>
            <div class="hint">Product #{{ $product->product_id }} @if(!empty($product->sku)) • SKU {{ $product->sku }} @endif</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('products.edit', $product->product_id) }}">Back to Product</a>
            <a class="btn secondary" href="{{ route('products.index') }}">Products</a>
        </div>
    </div>

    <form method="POST" action="{{ route('products.options.update', $product->product_id) }}">
        @csrf

        <div id="options-root"></div>

        <div class="d-flex gap-10 items-center mt-12">
            <button class="btn secondary" type="button" id="add-option-btn">Add Option</button>
            <button class="btn" type="submit">Save Options</button>
        </div>
    </form>
</div>

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
            <div style="margin-left:auto;">
                <button class="btn danger" type="button" data-action="remove-option">Remove</button>
            </div>
        </div>

        <div style="margin-top:10px;">
            <div class="hint">Values</div>
            <div class="js-values"></div>

            <div style="margin-top:10px; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
                <div class="typeahead" style="min-width:260px; flex:1;">
                    <label>Add value</label>
                    <input class="input js-value-search" placeholder="Type to search..." autocomplete="off">
                    <input type="hidden" class="js-value-id">
                    <div class="typeahead-list js-value-list"></div>
                </div>
                <div style="width:140px;">
                    <label>SKU</label>
                    <input class="input js-value-sku" placeholder="optional">
                </div>
                <div style="width:120px;">
                    <label>Qty</label>
                    <input class="input js-value-qty" type="number" min="0" value="0">
                </div>
                <div style="width:80px;">
                    <label>Price +/-</label>
                    <select class="input js-value-price-prefix">
                        <option value="+">+</option>
                        <option value="-">-</option>
                    </select>
                </div>
                <div style="width:140px;">
                    <label>Price Δ</label>
                    <input class="input js-value-price" type="number" step="0.01" value="0">
                </div>
                <div>
                    <button class="btn secondary" type="button" data-action="add-value">Add</button>
                </div>
            </div>
        </div>
    </div>
</template>

<template id="tpl-value-row">
    <div class="row" style="display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.06);">
        <input type="hidden" class="js-row-value-id">
        <div style="flex:1;">
            <div class="js-row-value-name" style="font-weight:600;"></div>
        </div>
        <div style="width:140px;">
            <input class="input js-row-sku" placeholder="SKU">
        </div>
        <div style="width:120px;">
            <input class="input js-row-qty" type="number" min="0">
        </div>
        <div style="width:80px;">
            <select class="input js-row-price-prefix">
                <option value="+">+</option>
                <option value="-">-</option>
            </select>
        </div>
        <div style="width:140px;">
            <input class="input js-row-price" type="number" step="0.01">
        </div>
        <div>
            <button class="btn danger" type="button" data-action="remove-value">Remove</button>
        </div>
    </div>
</template>
@endsection

@push('scripts')
<script>
const ALL_OPTIONS = @json($allOptions);
const EXISTING = @json($existingOptions);

function escHtml(s){
  return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function wordStartMatch(text, term){
  if(!text || !term) return false;
  const h = String(text).toLowerCase();
  const t = String(term).toLowerCase().trim();
  if(!t) return false;
  const esc = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp('(^|[^a-z0-9])' + esc);
  return re.test(h);
}

function makeValueTypeahead(container, optionId){
  const input = container.querySelector('.js-value-search');
  const list  = container.querySelector('.js-value-list');
  const hidden= container.querySelector('.js-value-id');
  let timer = null;

  function close(){ list.style.display='none'; list.innerHTML=''; }
  async function search(q){
    const url = `/api/catalog/options/${encodeURIComponent(optionId)}/values?term=` + encodeURIComponent(q);
    const res = await fetch(url, { headers: { 'Accept':'application/json' }, credentials: 'same-origin' });
    if(!res.ok) return [];
    return await res.json();
  }

  function render(items, q){
    list.innerHTML='';
    if(!items || !items.length){ close(); return; }
    items = items.filter(it => wordStartMatch(it.name || '', q));
    if(!items.length){ close(); return; }

    items.forEach(it => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = it.name;
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        input.value = it.name;
        hidden.value = it.option_value_id;
        close();
      });
      list.appendChild(btn);
    });
    list.style.display='block';
  }

  input.addEventListener('input', () => {
    const q = (input.value || '').trim();
    hidden.value = '';
    if(q.length < 1){ close(); return; }

    clearTimeout(timer);
    timer = setTimeout(async () => {
      const items = await search(q);
      render(items, q);
    }, 120);
  });

  document.addEventListener('click', (e) => {
    if(!list.contains(e.target) && e.target !== input) close();
  });
}

function addValueRow(optionBlock, data){
  const valuesWrap = optionBlock.querySelector('.js-values');
  const tpl = document.getElementById('tpl-value-row');
  const node = tpl.content.firstElementChild.cloneNode(true);

  node.querySelector('.js-row-value-id').value = data.option_value_id;
  node.querySelector('.js-row-value-name').textContent = data.option_value_name;
  node.querySelector('.js-row-sku').value = data.sku ?? '';
  node.querySelector('.js-row-qty').value = data.quantity ?? 0;
  node.querySelector('.js-row-price-prefix').value = data.price_prefix ?? '+';
  node.querySelector('.js-row-price').value = data.price ?? 0;

  node.querySelector('[data-action="remove-value"]').addEventListener('click', () => node.remove());

  valuesWrap.appendChild(node);
}

function buildOptionBlock(seed){
  const tpl = document.getElementById('tpl-option');
  const block = tpl.content.firstElementChild.cloneNode(true);

  const select = block.querySelector('.js-option-select');
  select.innerHTML = '<option value="">-- Select --</option>' + ALL_OPTIONS.map(o => `<option value="${o.option_id}" data-type="${escHtml(o.type)}">${escHtml(o.name)} (${escHtml(o.type)})</option>`).join('');

  const optionIdInput = block.querySelector('.js-option-id');
  const requiredSel = block.querySelector('.js-option-required');

  function syncOptionId(){
    optionIdInput.value = select.value || '';
    // reset value typeahead with new optionId
    const optionId = parseInt(select.value || '0', 10);
    if(optionId > 0){
      makeValueTypeahead(block, optionId);
    }
  }

  select.addEventListener('change', syncOptionId);
  block.querySelector('[data-action="remove-option"]').addEventListener('click', () => block.remove());

  block.querySelector('[data-action="add-value"]').addEventListener('click', () => {
    const optionId = parseInt(optionIdInput.value || '0', 10);
    if(optionId <= 0) return;

    const valueIdEl = block.querySelector('.js-value-id');
    const valueNameEl = block.querySelector('.js-value-search');
    const valId = parseInt(valueIdEl.value || '0', 10);
    const valName = (valueNameEl.value || '').trim();
    if(valId <= 0 || !valName) return;

    // prevent duplicates
    const exists = Array.from(block.querySelectorAll('.js-row-value-id')).some(i => parseInt(i.value,10) === valId);
    if(exists) return;

    const sku = block.querySelector('.js-value-sku').value || '';
    const qty = parseInt(block.querySelector('.js-value-qty').value || '0', 10);
    const pp = block.querySelector('.js-value-price-prefix').value || '+';
    const price = parseFloat(block.querySelector('.js-value-price').value || '0');

    addValueRow(block, {
      option_value_id: valId,
      option_value_name: valName,
      sku: sku,
      quantity: isNaN(qty) ? 0 : qty,
      price_prefix: pp,
      price: isNaN(price) ? 0 : price,
    });

    // clear add form
    valueIdEl.value = '';
    valueNameEl.value = '';
    block.querySelector('.js-value-sku').value = '';
    block.querySelector('.js-value-qty').value = '0';
    block.querySelector('.js-value-price-prefix').value = '+';
    block.querySelector('.js-value-price').value = '0';
  });

  // seed
  if(seed){
    select.value = String(seed.option_id || '');
    requiredSel.value = String(seed.required ?? 0);
    syncOptionId();
    (seed.values || []).forEach(v => {
      addValueRow(block, {
        option_value_id: v.option_value_id,
        option_value_name: v.option_value_name,
        sku: v.sku,
        quantity: v.quantity,
        price_prefix: v.price_prefix,
        price: v.price,
      });
    });
  }

  return block;
}

function serializeAndInject(){
  const root = document.getElementById('options-root');
  // remove any previous hidden inputs
  root.querySelectorAll('input[name^="options["]').forEach(n => n.remove());
  root.querySelectorAll('select[name^="options["]').forEach(n => n.remove());
}

function beforeSubmit(e){
  const root = document.getElementById('options-root');
  serializeAndInject();

  const blocks = Array.from(root.children);
  let idx = 0;
  for(const block of blocks){
    const optionId = parseInt(block.querySelector('.js-option-id').value || '0', 10);
    if(optionId <= 0) continue;

    const req = block.querySelector('.js-option-required').value || '0';

    // option_id
    root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][option_id]" value="${optionId}">`);
    root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][required]" value="${escHtml(req)}">`);

    const rows = Array.from(block.querySelectorAll('.js-values .row'));
    let vIdx = 0;
    for(const r of rows){
      const valId = parseInt(r.querySelector('.js-row-value-id').value || '0', 10);
      if(valId <= 0) continue;
      const sku = r.querySelector('.js-row-sku').value || '';
      const qty = r.querySelector('.js-row-qty').value || '0';
      const pp  = r.querySelector('.js-row-price-prefix').value || '+';
      const price = r.querySelector('.js-row-price').value || '0';

      root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][values][${vIdx}][option_value_id]" value="${valId}">`);
      root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][values][${vIdx}][sku]" value="${escHtml(sku)}">`);
      root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][values][${vIdx}][quantity]" value="${escHtml(qty)}">`);
      root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][values][${vIdx}][price_prefix]" value="${escHtml(pp)}">`);
      root.insertAdjacentHTML('beforeend', `<input type="hidden" name="options[${idx}][values][${vIdx}][price]" value="${escHtml(price)}">`);
      vIdx++;
    }

    idx++;
  }
}

// init
const root = document.getElementById('options-root');
EXISTING.forEach(seed => root.appendChild(buildOptionBlock(seed)));

document.getElementById('add-option-btn').addEventListener('click', () => {
  root.appendChild(buildOptionBlock(null));
});

// hook submit
document.querySelector('form').addEventListener('submit', beforeSubmit);
</script>
@endpush
