@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Product #{{ $product->product_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ $returnUrl ?? route('products.index') }}">Back</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('products.update', $product->product_id) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_return" value="{{ $returnUrl ?? route('products.index') }}">

        <div class="tabs mb-12">
            <button type="button" class="tab active" data-tab="prod-tab-general">General</button>
            <button type="button" class="tab" data-tab="prod-tab-options">Options</button>
            <button type="button" class="tab" data-tab="prod-tab-vendor">Vendor</button>
        </div>

        {{-- ===== GENERAL TAB ===== --}}
        <div id="prod-tab-general">

            <div class="typeahead mt-16">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name', $manufacturerName ?? '') }}" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', $product->manufacturer_id) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result. Required before uploading images.</div>
            </div>

            @include('products.partials.image_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

            <h3 class="section-title">General</h3>

            <div class="form-grid">
                <div class="full">
                    <label class="required">Name</label>
                    <div class="input-group">
                        <span class="input-group-prefix" id="name_mfg_prefix" style="display:none;"></span>
                        <input class="input" name="name" id="product_name" value="{{ old('name', html_entity_decode($product->name ?? '')) }}" required>
                    </div>
                    @error('name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">Model</label>
                    <input class="input" name="model" value="{{ old('model', $product->model) }}" required>
                    @error('model')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">SKU</label>
                    <input class="input" name="sku" value="{{ old('sku', $product->sku) }}" required>
                    @error('sku')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label>Quantity</label>
                    <input class="input" id="product_quantity" name="quantity" value="{{ old('quantity', $product->quantity) }}" required>
                    <div class="hint" id="qty_hint" style="display:none;">Quantity is auto-calculated from option quantities.</div>
                </div>

                <div>
                    <label class="required">Status</label>
                    <select class="input" name="status" required>
                        <option value="1" {{ old('status', (string)$product->status)=='1'?'selected':'' }}>Enabled</option>
                        <option value="0" {{ old('status', (string)$product->status)=='0'?'selected':'' }}>Disabled</option>
                    </select>
                </div>

                <div class="typeahead full">
                    <label>Categories</label>
                    <div id="category_tags" class="tag-container">
                        @foreach(old('category_ids', collect($currentCategories ?? [])->pluck('id')->all()) as $catId)
                            @php
                                $catName = collect($currentCategories ?? [])->firstWhere('id', $catId)['name'] ?? 'Category #'.$catId;
                            @endphp
                            <span class="tag-chip" data-id="{{ $catId }}">
                                {{ $catName }}
                                <input type="hidden" name="category_ids[]" value="{{ $catId }}">
                                <button type="button" class="tag-remove" onclick="this.parentElement.remove(); window.updateCatPlaceholder();">&times;</button>
                            </span>
                        @endforeach
                    </div>
                    <input class="input" id="category_search" placeholder="Type to search categories..." autocomplete="off">
                    <div class="typeahead-list" id="category_list"></div>
                    <div class="hint">Search and select multiple categories.</div>
                </div>

                <div class="full">
                    <label>Description</label>
                    <textarea class="input wysiwyg" name="description">{!! old('description', $product->description) !!}</textarea>
                </div>
            </div>

            <h3 class="section-title mt-24">Pricing & Cost</h3>

            <div class="form-grid mt-16">
                <div>
                    <label>Selling Price</label>
                    <input class="input" name="price" id="product_price" value="{{ old('price', $product->price) }}" required>
                </div>
            </div>

            <div class="cost-breakdown mt-16">
                <div class="cost-breakdown-label" style="display:flex; align-items:center; gap:8px;">
                    Cost Breakdown
                    <span id="cost-lock-badge" style="font-size:11px; padding:2px 8px; border-radius:4px; background:var(--surface-alt); color:#94a3b8;">Locked — updated on PO receive</span>
                    <button type="button" id="cost-override-btn" class="btn secondary small" style="font-size:11px; padding:2px 8px;">Override</button>
                </div>
                <div class="cost-breakdown-fields">
                    <div>
                        <label>Cost</label>
                        <input class="input" id="cost_amount" name="cost_amount" value="{{ old('cost_amount', $product->cost_amount ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">Fixed amount</div>
                    </div>
                    <div class="cost-breakdown-op">and/or</div>
                    <div>
                        <label>Cost [%]</label>
                        <input class="input" id="cost_percentage" name="cost_percentage" value="{{ old('cost_percentage', $product->cost_percentage ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">% of selling price</div>
                    </div>
                    <div class="cost-breakdown-op">+</div>
                    <div>
                        <label>Additional Cost</label>
                        <input class="input" id="cost_additional" name="cost_additional" value="{{ old('cost_additional', $product->cost_additional ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">e.g. supplier shipping</div>
                    </div>
                    <div class="cost-breakdown-op">=</div>
                    <div>
                        <label>Product Cost</label>
                        <input class="input" id="product_cost" name="cost" value="{{ old('cost', $product->cost ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                        <div class="hint">Computed total</div>
                    </div>
                </div>
                <div class="profit-metrics">
                    <div>
                        <label>Profit</label>
                        <input class="input" id="calc_profit" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                    </div>
                    <div>
                        <label>Margin [%]</label>
                        <input class="input" id="calc_margin" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                    </div>
                    <div>
                        <label>Markup [%]</label>
                        <input class="input" id="calc_markup" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                    </div>
                </div>
            </div>

            <h3 class="section-title mt-24">Shipping & Dimensions</h3>

            <div class="form-grid mt-16">
                <div>
                    <label>Weight</label>
                    <input class="input" name="weight" value="{{ old('weight', $product->weight ?? '0') }}" placeholder="e.g. 0.5">
                    @error('weight') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Length</label>
                    <input class="input" name="length" value="{{ old('length', $product->length ?? '0') }}" placeholder="e.g. 10">
                    @error('length') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Width</label>
                    <input class="input" name="width" value="{{ old('width', $product->width ?? '0') }}" placeholder="e.g. 5">
                    @error('width') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Height</label>
                    <input class="input" name="height" value="{{ old('height', $product->height ?? '0') }}" placeholder="e.g. 3">
                    @error('height') <div class="field-error">{{ $message }}</div> @enderror
                </div>
            </div>

        </div>

        {{-- ===== OPTIONS TAB ===== --}}
        <div id="prod-tab-options" class="hidden">
            @include('products.partials.options_fields')
        </div>

        {{-- ===== VENDOR TAB ===== --}}
        <div id="prod-tab-vendor" class="hidden">

            <div class="form-grid mt-16">
                <div>
                    <label>Reorder Level</label>
                    <input class="input" type="number" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level ?? 0) }}" min="0">
                    <div class="hint">Alert when stock falls to this level (0 = disabled)</div>
                </div>
            </div>

            <h3 class="section-title mt-24">Buying Prices</h3>
            <div id="buying-prices-section" class="mt-8">

                {{-- Existing vendor pricing — inline-editable --}}
                <div class="table-wrap">
                    <table id="buying-prices-table" style="font-size:12px;">
                        <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Option</th>
                            <th style="width:120px;">Vendor SKU</th>
                            <th style="width:70px;">Min Qty</th>
                            <th style="width:85px;">Currency</th>
                            <th style="width:110px;">Unit Price</th>
                            <th style="width:130px;"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($productVendors as $pv)
                            <tr data-pv-id="{{ $pv->id }}" data-vendor-id="{{ $pv->vendor_id }}" data-pov-id="{{ $pv->product_option_value_id ?? '' }}">
                                <td>{{ $pv->vendor->name ?? '—' }}</td>
                                <td class="pv-option-label" data-pov-id="{{ $pv->product_option_value_id }}"></td>
                                <td><input class="input pv-edit-sku" value="{{ $pv->vendor_sku ?? '' }}" style="width:110px; font-size:12px;"></td>
                                <td><input class="input pv-edit-minqty" type="number" min="1" value="{{ $pv->min_qty }}" style="width:60px; font-size:12px;"></td>
                                <td>
                                    <select class="input pv-edit-currency" style="width:80px; font-size:12px;">
                                        @foreach($currencies as $c)
                                            <option value="{{ $c->code }}" {{ $c->code === $pv->currency_code ? 'selected' : '' }}>{{ $c->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input class="input pv-edit-price" type="number" step="0.0001" min="0" value="{{ $pv->unit_price }}" style="width:100px; font-size:12px; text-align:right;"></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn small pv-save-row-btn" data-id="{{ $pv->id }}" style="display:none;">Save</button>
                                    <button type="button" class="btn danger small pv-remove-btn" data-id="{{ $pv->id }}">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div id="pv-empty-msg" class="text-secondary text-xs mt-8" style="display:none;">No vendor pricing yet.</div>

                {{-- Add new vendor pricing --}}
                <div id="pv-add-section" class="mt-16">
                    <div style="font-size:14px; font-weight:600; margin-bottom:8px;">Add New</div>

                    {{-- Simple mode: product without options --}}
                    <div id="pv-simple-fields">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                            <div class="typeahead" style="position:relative;">
                                <label>Vendor</label>
                                <input class="input" id="pv-vendor-search" placeholder="Type to search..." autocomplete="off" style="width:200px;">
                                <input type="hidden" id="pv-vendor-id">
                                <div class="typeahead-list" id="pv-vendor-list"></div>
                            </div>
                            <div>
                                <label>Vendor SKU</label>
                                <input id="pv-vendor-sku" class="input" style="width:130px;">
                            </div>
                            <div>
                                <label>Min Qty</label>
                                <input id="pv-min-qty" class="input" type="number" min="1" value="1" style="width:80px;">
                            </div>
                            <div>
                                <label>Currency</label>
                                <select id="pv-currency" class="input" style="width:100px;">
                                    @foreach($currencies as $c)
                                        <option value="{{ $c->code }}">{{ $c->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Unit Price</label>
                                <input id="pv-unit-price" class="input" type="number" step="0.0001" min="0" style="width:120px;">
                            </div>
                            <button type="button" class="btn secondary" id="pv-add-btn">Add</button>
                        </div>
                    </div>

                    {{-- Options mode: per-option-value vendor pricing --}}
                    <div id="pv-option-fields" class="hidden">
                        <div class="d-flex items-center justify-between mb-8">
                            <div class="text-xs text-secondary" id="pv-add-hint">Fill in vendor details for each option below.</div>
                            <button type="button" class="btn secondary small" id="pv-apply-all-btn" title="Copy vendor, currency, and min qty from the first row to all other rows" style="white-space:nowrap;">Copy first row &darr;</button>
                        </div>
                        <div class="table-wrap">
                        <table id="pv-option-table" style="font-size:13px;">
                            <thead>
                            <tr>
                                <th style="text-align:left;">Option</th>
                                <th style="width:160px;">Vendor</th>
                                <th style="width:80px;">Currency</th>
                                <th style="width:70px;">Min Qty</th>
                                <th style="width:130px;">Vendor SKU</th>
                                <th style="width:110px;">Unit Price</th>
                            </tr>
                            </thead>
                            <tbody id="pv-option-tbody"></tbody>
                        </table>
                        </div>
                        <div id="pv-add-btns" class="mt-8 d-flex gap-8 items-center justify-end">
                            <span id="pv-bulk-status" style="font-size:12px; display:none;"></span>
                            <button type="button" class="btn" id="pv-add-bulk-btn">Add Vendors</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var productId = {{ (int) $product->product_id }};
                var baseUrl = '/products/' + productId + '/vendors';
                var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

                var vendorList = @json($vendors ?? []);
                var currencyList = @json($currencies->pluck('code'));

                // Build option value map
                var optionValues = [];
                @if($existingOptions->isNotEmpty())
                    @foreach($existingOptions as $opt)
                        @if(!empty($opt->values))
                            @foreach($opt->values as $val)
                                optionValues.push({
                                    product_option_value_id: {{ (int)$val['product_option_value_id'] }},
                                    label: {!! json_encode(html_entity_decode($opt->name, ENT_QUOTES|ENT_HTML5, 'UTF-8') . ': ' . ($val['name'] ?? '')) !!},
                                    sku: {!! json_encode($val['sku'] ?? '') !!}
                                });
                            @endforeach
                        @endif
                    @endforeach
                @endif

                var hasOptions = optionValues.length > 0;

                // Show the right form mode
                if (hasOptions) {
                    document.getElementById('pv-simple-fields').classList.add('hidden');
                    document.getElementById('pv-option-fields').classList.remove('hidden');
                    buildOptionRows();
                }

                // Fill option labels in existing table
                document.querySelectorAll('.pv-option-label').forEach(function(td) {
                    var povId = parseInt(td.dataset.povId);
                    if (!povId) { td.textContent = '—'; return; }
                    var ov = optionValues.find(function(v) { return v.product_option_value_id === povId; });
                    td.textContent = ov ? ov.label : '#' + povId;
                });

                // Show empty message if no rows
                function checkEmpty() {
                    var rows = document.querySelectorAll('#buying-prices-table tbody tr[data-pv-id]');
                    document.getElementById('pv-empty-msg').style.display = rows.length === 0 ? '' : 'none';
                }
                checkEmpty();

                function buildCurrencySelect(selectedCode) {
                    var html = '';
                    currencyList.forEach(function(c) {
                        html += '<option value="' + c + '"' + (c === selectedCode ? ' selected' : '') + '>' + c + '</option>';
                    });
                    return html;
                }

                // ══════════════════════════════════════════════
                // INLINE EDIT — existing pricing rows
                // ══════════════════════════════════════════════

                // Show Save button when any input changes in a row
                document.getElementById('buying-prices-table').addEventListener('input', function(e) {
                    var tr = e.target.closest('tr[data-pv-id]');
                    if (!tr) return;
                    var saveBtn = tr.querySelector('.pv-save-row-btn');
                    if (saveBtn) saveBtn.style.display = '';
                });

                // Save inline edit
                document.getElementById('buying-prices-table').addEventListener('click', function(e) {
                    var saveBtn = e.target.closest('.pv-save-row-btn');
                    if (saveBtn) {
                        var tr = saveBtn.closest('tr');
                        var pvId = saveBtn.dataset.id;
                        saveBtn.disabled = true;
                        saveBtn.textContent = '…';

                        fetch(baseUrl + '/' + pvId, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                            body: JSON.stringify({
                                vendor_sku: tr.querySelector('.pv-edit-sku').value || null,
                                min_qty: parseInt(tr.querySelector('.pv-edit-minqty').value) || 1,
                                currency_code: tr.querySelector('.pv-edit-currency').value,
                                unit_price: parseFloat(tr.querySelector('.pv-edit-price').value) || 0,
                            })
                        })
                        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
                        .then(function(res) {
                            saveBtn.disabled = false;
                            if (res.ok) {
                                saveBtn.style.display = 'none';
                                saveBtn.textContent = 'Save';
                            } else {
                                saveBtn.textContent = 'Save';
                                alert(res.data.error || res.data.message || 'Error saving.');
                            }
                        })
                        .catch(function() { saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
                        return;
                    }

                    // Remove single row
                    var removeBtn = e.target.closest('.pv-remove-btn');
                    if (removeBtn) {
                        if (!confirm('Remove this vendor link?')) return;
                        var pvId = removeBtn.dataset.id;
                        fetch(baseUrl + '/' + pvId, {
                            method: 'DELETE',
                            headers: { 'X-CSRF-TOKEN': csrfToken }
                        })
                        .then(function(r) {
                            if (!r.ok) return;
                            removeBtn.closest('tr').remove();
                            groupExistingTable();
                            checkEmpty();
                        });
                    }
                });

                // Bulk remove — by vendor
                function bulkRemove(vendorId) {
                    fetch(baseUrl, {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({ vendor_id: parseInt(vendorId) })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.ok) return;
                        var tbody = document.querySelector('#buying-prices-table tbody');
                        tbody.querySelectorAll('tr[data-vendor-id="' + vendorId + '"]').forEach(function(tr) {
                            tr.remove();
                        });
                        groupExistingTable();
                        checkEmpty();
                    });
                }

                // ══════════════════════════════════════════════
                // VENDOR GROUPING — existing table
                // ══════════════════════════════════════════════

                function groupExistingTable() {
                    var tbody = document.querySelector('#buying-prices-table tbody');
                    tbody.querySelectorAll('.pv-vendor-spacer, .pv-vendor-header').forEach(function(el) { el.remove(); });

                    var rows = Array.from(tbody.querySelectorAll('tr[data-pv-id]'));
                    if (rows.length === 0) return;

                    var groups = {};
                    var order = [];
                    rows.forEach(function(tr) {
                        var vid = tr.dataset.vendorId || '0';
                        var name = tr.querySelector('td').textContent.trim();
                        if (!groups[vid]) { groups[vid] = { name: name, rows: [] }; order.push(vid); }
                        groups[vid].rows.push(tr);
                    });

                    order.forEach(function(vid, gIdx) {
                        var g = groups[vid];

                        if (gIdx > 0) {
                            var spacer = document.createElement('tr');
                            spacer.className = 'pv-vendor-spacer';
                            spacer.innerHTML = '<td colspan="7" style="height:0; padding:0; border:none;"><div style="height:12px; margin:4px 0; border-top:2px solid var(--border-color, #cbd5e1);"></div></td>';
                            tbody.appendChild(spacer);
                        }

                        var header = document.createElement('tr');
                        header.className = 'pv-vendor-header';
                        header.style.cssText = 'background:var(--surface-alt, #f8fafc);';
                        var td = document.createElement('td');
                        td.colSpan = 7;
                        td.style.cssText = 'padding:0;';
                        var wrap = document.createElement('div');
                        wrap.style.cssText = 'display:flex; align-items:center; justify-content:space-between; padding:8px 10px; font-weight:600; font-size:13px;';

                        var labelSpan = document.createElement('span');
                        labelSpan.style.cursor = 'pointer';
                        labelSpan.innerHTML = '<span class="pv-chevron" style="display:inline-block; width:16px; transition:transform .15s;">&#9656;</span> '
                            + escHtml(g.name) + ' <span style="color:#94a3b8; font-weight:400;">(' + g.rows.length + ')</span>';
                        wrap.appendChild(labelSpan);

                        var removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'btn danger small';
                        removeBtn.textContent = 'Remove vendor';
                        removeBtn.style.cssText = 'font-size:11px; padding:2px 8px; flex-shrink:0; margin-left:12px;';
                        (function(v, n) {
                            removeBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                if (!confirm('Remove all entries for ' + n + '?')) return;
                                bulkRemove(v);
                            });
                        })(vid, g.name);
                        wrap.appendChild(removeBtn);
                        td.appendChild(wrap);
                        header.appendChild(td);
                        tbody.appendChild(header);

                        g.rows.forEach(function(tr) {
                            tr.style.display = 'none';
                            tbody.appendChild(tr);
                        });

                        (function(hdr, grp) {
                            hdr.querySelector('span[style*="cursor"]').addEventListener('click', function() {
                                var chevron = hdr.querySelector('.pv-chevron');
                                var hidden = grp.rows[0].style.display === 'none';
                                grp.rows.forEach(function(tr) { tr.style.display = hidden ? '' : 'none'; });
                                chevron.style.transform = hidden ? 'rotate(90deg)' : 'rotate(0deg)';
                            });
                        })(header, g);
                    });
                }

                groupExistingTable();

                // ══════════════════════════════════════════════
                // ADD NEW — option rows (only unlinked options)
                // ══════════════════════════════════════════════

                function updateVendorGrouping() {
                    var tbody = document.getElementById('pv-option-tbody');
                    tbody.querySelectorAll('.pv-vendor-spacer').forEach(function(el) { el.remove(); });
                    var rows = Array.from(tbody.querySelectorAll('tr[data-pov]'));
                    var prevVid = null;
                    rows.forEach(function(tr) {
                        var vid = tr.querySelector('.pv-var-vendor').value || null;
                        if (prevVid !== null && vid && vid !== prevVid) {
                            var spacer = document.createElement('tr');
                            spacer.className = 'pv-vendor-spacer';
                            spacer.innerHTML = '<td colspan="6" style="height:0; padding:0; border:none;"><div style="height:12px; margin:4px 0; border-top:2px solid var(--border-color, #cbd5e1);"></div></td>';
                            tr.parentNode.insertBefore(spacer, tr);
                        }
                        if (vid) prevVid = vid;
                    });
                }

                function setupRowVendorTypeahead(inputEl, hiddenEl, listEl, currencyEl) {
                    var timer = null;
                    function hideList() { listEl.style.display = 'none'; }
                    function showList() { listEl.style.display = 'block'; }
                    function render(items) {
                        listEl.innerHTML = '';
                        if (!items.length) {
                            var empty = document.createElement('div');
                            empty.style.cssText = 'padding:8px 10px;color:#94a3b8;font-size:12px;';
                            empty.textContent = 'No vendors found';
                            listEl.appendChild(empty);
                            showList();
                            return;
                        }
                        items.forEach(function(v) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.textContent = v.name;
                            btn.style.cssText = 'display:block;width:100%;text-align:left;padding:6px 10px;border:none;background:none;cursor:pointer;font-size:12px;';
                            btn.addEventListener('mouseenter', function() { btn.style.background = '#f1f5f9'; });
                            btn.addEventListener('mouseleave', function() { btn.style.background = 'none'; });
                            btn.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                inputEl.value = v.name;
                                hiddenEl.value = v.id;
                                hideList();
                                if (v.currency_code && currencyEl) currencyEl.value = v.currency_code;
                                updateVendorGrouping();
                            });
                            listEl.appendChild(btn);
                        });
                        showList();
                    }
                    function filter(q) {
                        q = q.toLowerCase();
                        if (!q) return vendorList.slice(0, 50);
                        return vendorList.filter(function(v) {
                            return v.name.toLowerCase().indexOf(q) !== -1;
                        }).slice(0, 50);
                    }
                    inputEl.addEventListener('input', function() {
                        hiddenEl.value = '';
                        clearTimeout(timer);
                        timer = setTimeout(function() { render(filter(inputEl.value.trim())); }, 80);
                    });
                    inputEl.addEventListener('focus', function() { render(filter(inputEl.value.trim())); });
                    document.addEventListener('click', function(e) {
                        if (e.target !== inputEl && !listEl.contains(e.target)) hideList();
                    });
                }

                function buildOptionRows() {
                    var tbody = document.getElementById('pv-option-tbody');
                    tbody.innerHTML = '';

                    optionValues.forEach(function(ov) {
                        var tr = document.createElement('tr');
                        tr.dataset.pov = ov.product_option_value_id;
                        tr.innerHTML =
                            '<td style="font-size:12px;">' + escHtml(ov.label) + ' <span style="color:#94a3b8;">[' + escHtml(ov.sku) + ']</span></td>'
                            + '<td style="position:relative;">'
                                + '<input class="input pv-var-vendor-input" autocomplete="off" placeholder="Search vendor…" style="width:150px; font-size:12px;">'
                                + '<input type="hidden" class="pv-var-vendor">'
                                + '<div class="pv-var-vendor-list" style="display:none;position:absolute;z-index:50;background:#fff;border:1px solid #cbd5e1;border-radius:4px;max-height:180px;overflow-y:auto;width:200px;box-shadow:0 4px 12px rgba(0,0,0,.12);"></div>'
                            + '</td>'
                            + '<td><select class="input pv-var-currency" style="width:75px; font-size:12px;">' + buildCurrencySelect('PHP') + '</select></td>'
                            + '<td><input class="input pv-var-minqty" type="number" min="1" value="1" style="width:60px; font-size:12px;"></td>'
                            + '<td><input class="input pv-var-sku" value="' + escHtml(ov.sku) + '" style="width:120px; font-size:12px;"></td>'
                            + '<td><input class="input pv-var-price" type="number" step="0.0001" min="0" value="0" style="width:100px; font-size:12px;"></td>';
                        tbody.appendChild(tr);

                        setupRowVendorTypeahead(
                            tr.querySelector('.pv-var-vendor-input'),
                            tr.querySelector('.pv-var-vendor'),
                            tr.querySelector('.pv-var-vendor-list'),
                            tr.querySelector('.pv-var-currency')
                        );
                    });
                }

                // "Copy first row" button
                document.getElementById('pv-apply-all-btn').addEventListener('click', function() {
                    var rows = document.querySelectorAll('#pv-option-tbody tr[data-pov]');
                    if (rows.length < 2) return;
                    var first = rows[0];
                    var vendorId = first.querySelector('.pv-var-vendor').value;
                    var vendorName = first.querySelector('.pv-var-vendor-input').value;
                    var currencyVal = first.querySelector('.pv-var-currency').value;
                    var minQtyVal = first.querySelector('.pv-var-minqty').value;
                    if (!vendorId) { alert('Please select a vendor in the first row first.'); return; }
                    for (var i = 1; i < rows.length; i++) {
                        rows[i].querySelector('.pv-var-vendor').value = vendorId;
                        rows[i].querySelector('.pv-var-vendor-input').value = vendorName;
                        rows[i].querySelector('.pv-var-currency').value = currencyVal;
                        rows[i].querySelector('.pv-var-minqty').value = minQtyVal;
                    }
                    updateVendorGrouping();
                });

                // ══════════════════════════════════════════════
                // SIMPLE ADD (product-level, no options)
                // ══════════════════════════════════════════════

                if (window.setupTypeahead) {
                    window.setupTypeahead({
                        inputId: 'pv-vendor-search',
                        hiddenId: 'pv-vendor-id',
                        listId: 'pv-vendor-list',
                        url: '/api/vendors?q=',
                        showAllOnFocus: true,
                        onSelect: function(item) {
                            if (item.currency_code) document.getElementById('pv-currency').value = item.currency_code;
                        }
                    });
                }

                document.getElementById('pv-add-btn').addEventListener('click', function() {
                    var vendorId = document.getElementById('pv-vendor-id').value;
                    if (!vendorId) { alert('Please select a vendor.'); return; }
                    fetch(baseUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({
                            vendor_id: vendorId,
                            vendor_sku: document.getElementById('pv-vendor-sku').value || null,
                            min_qty: parseInt(document.getElementById('pv-min-qty').value) || 1,
                            currency_code: document.getElementById('pv-currency').value,
                            unit_price: parseFloat(document.getElementById('pv-unit-price').value) || 0,
                        })
                    })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
                    .then(function(res) {
                        if (!res.ok) { alert(res.data.error || 'Error adding vendor.'); return; }
                        appendRow(res.data);
                        document.getElementById('pv-vendor-id').value = '';
                        document.getElementById('pv-vendor-search').value = '';
                        document.getElementById('pv-vendor-sku').value = '';
                        document.getElementById('pv-min-qty').value = '1';
                        document.getElementById('pv-unit-price').value = '';
                    });
                });

                // ══════════════════════════════════════════════
                // BULK ADD (per-option)
                // ══════════════════════════════════════════════

                document.getElementById('pv-add-bulk-btn').addEventListener('click', function() {
                    var btn = this;
                    var options = [];
                    document.querySelectorAll('#pv-option-tbody tr[data-pov]').forEach(function(tr) {
                        var vendorId = tr.querySelector('.pv-var-vendor').value;
                        if (!vendorId) return;
                        options.push({
                            product_option_value_id: parseInt(tr.dataset.pov),
                            vendor_id: parseInt(vendorId),
                            currency_code: tr.querySelector('.pv-var-currency').value,
                            min_qty: parseInt(tr.querySelector('.pv-var-minqty').value) || 1,
                            vendor_sku: tr.querySelector('.pv-var-sku').value || null,
                            unit_price: parseFloat(tr.querySelector('.pv-var-price').value) || 0,
                        });
                    });
                    if (options.length === 0) { alert('Please select a vendor for at least one option.'); return; }

                    btn.disabled = true;
                    btn.textContent = 'Saving…';

                    fetch(baseUrl + '/bulk', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({ options: options })
                    })
                    .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.textContent = 'Add Vendors';
                        if (!res.ok) { alert(res.data.error || res.data.message || 'Error saving.'); return; }
                        var count = res.data.length;
                        res.data.forEach(function(pv) { appendRow(pv); });
                        var status = document.getElementById('pv-bulk-status');
                        if (status && count > 0) {
                            status.textContent = count + ' added';
                            status.style.display = 'inline';
                            status.style.color = '#10b981';
                            setTimeout(function() { status.style.display = 'none'; }, 3000);
                        } else if (status && count === 0) {
                            status.textContent = 'All already exist';
                            status.style.display = 'inline';
                            status.style.color = '#f59e0b';
                            setTimeout(function() { status.style.display = 'none'; }, 3000);
                        }
                        // Reset add form for next use
                        buildOptionRows();
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        btn.textContent = 'Add Vendors';
                        alert('Network error: ' + (err.message || 'Request failed'));
                    });
                });

                // ══════════════════════════════════════════════
                // APPEND ROW — inline-editable format
                // ══════════════════════════════════════════════

                function appendRow(pv) {
                    var tbody = document.querySelector('#buying-prices-table tbody');
                    if (tbody.querySelector('tr[data-pv-id="' + pv.id + '"]')) return;

                    var tr = document.createElement('tr');
                    tr.dataset.pvId = pv.id;
                    tr.dataset.vendorId = pv.vendor_id || '';
                    tr.dataset.povId = pv.product_option_value_id || '';
                    var optionLabel = '—';
                    if (pv.product_option_value_id) {
                        var ov = optionValues.find(function(v) { return v.product_option_value_id === pv.product_option_value_id; });
                        optionLabel = ov ? ov.label : '#' + pv.product_option_value_id;
                    }
                    tr.innerHTML = '<td>' + escHtml(pv.vendor ? pv.vendor.name : '—') + '</td>'
                        + '<td>' + escHtml(optionLabel) + '</td>'
                        + '<td><input class="input pv-edit-sku" value="' + escHtml(pv.vendor_sku || '') + '" style="width:110px; font-size:12px;"></td>'
                        + '<td><input class="input pv-edit-minqty" type="number" min="1" value="' + pv.min_qty + '" style="width:60px; font-size:12px;"></td>'
                        + '<td><select class="input pv-edit-currency" style="width:80px; font-size:12px;">' + buildCurrencySelect(pv.currency_code) + '</select></td>'
                        + '<td><input class="input pv-edit-price" type="number" step="0.0001" min="0" value="' + pv.unit_price + '" style="width:100px; font-size:12px; text-align:right;"></td>'
                        + '<td style="white-space:nowrap;">'
                            + '<button type="button" class="btn small pv-save-row-btn" data-id="' + pv.id + '" style="display:none;">Save</button> '
                            + '<button type="button" class="btn danger small pv-remove-btn" data-id="' + pv.id + '">Remove</button>'
                        + '</td>';
                    tbody.appendChild(tr);
                    groupExistingTable();
                    checkEmpty();
                }

                function escHtml(s) {
                    var d = document.createElement('div');
                    d.textContent = s || '';
                    return d.innerHTML;
                }
            });
            </script>

        </div>

        <div class="d-flex gap-10 items-center justify-end mt-20">
            <button class="btn" type="submit">Update</button>
            <button class="btn danger" type="button" onclick="if(confirm('Delete this item?')) document.getElementById('delete-form').submit();">Delete</button>
        </div>
    </form>

    <form id="delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
</div>

<script>
(function() {
    document.querySelectorAll('.tabs .tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tabs .tab').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('prod-tab-general').classList.add('hidden');
            document.getElementById('prod-tab-options').classList.add('hidden');
            document.getElementById('prod-tab-vendor').classList.add('hidden');
            document.getElementById(btn.dataset.tab).classList.remove('hidden');
        });
    });
})();
</script>
@endsection

@push('scripts')
<script>
(function() {
    var price = document.getElementById('product_price');
    var amt   = document.getElementById('cost_amount');
    var pct   = document.getElementById('cost_percentage');
    var add   = document.getElementById('cost_additional');
    var out   = document.getElementById('product_cost');
    var mirror = document.getElementById('product_cost_mirror');

    var profitEl = document.getElementById('calc_profit');
    var marginEl = document.getElementById('calc_margin');
    var markupEl = document.getElementById('calc_markup');

    function calc() {
        var p = parseFloat(price.value) || 0;
        var a = parseFloat(amt.value) || 0;
        var c = parseFloat(pct.value) || 0;
        var d = parseFloat(add.value) || 0;
        var cost = a + (c / 100 * p) + d;
        var result = cost.toFixed(4);
        out.value = result;
        if (mirror) mirror.value = result;

        var profit = p - cost;
        if (profitEl) profitEl.value = profit.toFixed(2);
        if (marginEl) marginEl.value = p > 0 ? (profit / p * 100).toFixed(2) + '%' : '0%';
        if (markupEl) markupEl.value = cost > 0 ? (profit / cost * 100).toFixed(2) + '%' : '0%';

        if (profitEl) profitEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
        if (marginEl) marginEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
        if (markupEl) markupEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
    }

    [price, amt, pct, add].forEach(function(el) { el.addEventListener('input', calc); });
    calc();

    // Cost override toggle
    var overrideBtn = document.getElementById('cost-override-btn');
    var lockBadge = document.getElementById('cost-lock-badge');
    var costFields = [amt, pct, add];
    var unlocked = false;

    if (overrideBtn) {
        overrideBtn.addEventListener('click', function() {
            if (!unlocked) {
                if (!confirm('Cost is normally updated when receiving a PO. Are you sure you want to edit manually?')) return;
                costFields.forEach(function(el) {
                    el.removeAttribute('readonly');
                    el.removeAttribute('tabindex');
                    el.style.background = '';
                });
                lockBadge.textContent = 'Manual override';
                lockBadge.style.background = 'var(--warning-bg, #fef3c7)';
                lockBadge.style.color = 'var(--warning, #d97706)';
                overrideBtn.textContent = 'Lock';
                unlocked = true;
            } else {
                costFields.forEach(function(el) {
                    el.setAttribute('readonly', true);
                    el.setAttribute('tabindex', '-1');
                    el.style.background = 'var(--surface-alt)';
                });
                lockBadge.textContent = 'Locked \u2014 updated on PO receive';
                lockBadge.style.background = 'var(--surface-alt)';
                lockBadge.style.color = '#94a3b8';
                overrideBtn.textContent = 'Override';
                unlocked = false;
            }
        });
    }
})();
</script>
@endpush
