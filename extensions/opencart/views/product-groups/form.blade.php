@extends('layouts.app')
@section('breadcrumb', 'Marketplace / ' . $setting->store_name . ' / Product Groups / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Product Group' : 'Edit Product Group' }}</h2>
            <div class="text-muted text-sm">{{ $setting->store_name }} — filter products by category/manufacturer.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.opencart.product-groups.index', $setting->id) }}">Back to Product Groups</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert danger">
            <strong>Fix the following:</strong>
            <ul style="margin: 6px 0 0 18px;">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ $mode === 'create' ? route('ext.opencart.product-groups.store', $setting->id) : route('ext.opencart.product-groups.update', [$setting->id, $group->id]) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div>
                <label>Product Group Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $group->name) }}" placeholder="e.g. Guitar Pedals">
            </div>

            <h3 class="section-title" style="margin-top:20px;">Product Filter</h3>
            <div class="hint" style="margin-bottom:8px;">Select categories and/or manufacturers to match products. You can also add individual products manually from the Products tab.</div>

            @php
                $selectedCatIds = old('catalog_category_ids', $group->catalog_category_ids ?? []) ?? [];
                $selectedMfgIds = old('manufacturer_ids', $group->manufacturer_ids ?? []) ?? [];
                $selectedCatIds = array_map('intval', (array)$selectedCatIds);
                $selectedMfgIds = array_map('intval', (array)$selectedMfgIds);
            @endphp

            <div class="hint" style="margin-bottom:8px;">Type to search, click to add.</div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Categories</label>
                    <div style="position:relative;">
                        <input type="text" class="input" id="catSearch" placeholder="Search categories..." autocomplete="off">
                        <div id="catDropdown" style="display:none; position:absolute; z-index:10; left:0; right:0; max-height:200px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); margin-top:2px;"></div>
                    </div>
                    <div id="catSelected" style="border:1px solid var(--border); border-radius:var(--radius-md); min-height:40px; margin-top:8px; padding:4px;"></div>
                </div>
                <div>
                    <label>Manufacturers</label>
                    <div style="position:relative;">
                        <input type="text" class="input" id="mfgSearch" placeholder="Search manufacturers..." autocomplete="off">
                        <div id="mfgDropdown" style="display:none; position:absolute; z-index:10; left:0; right:0; max-height:200px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); margin-top:2px;"></div>
                    </div>
                    <div id="mfgSelected" style="border:1px solid var(--border); border-radius:var(--radius-md); min-height:40px; margin-top:8px; padding:4px;"></div>
                </div>
            </div>
            <div class="hint" style="margin-top:6px;">
                Matching products: <strong>{{ $productCount }}</strong>
                @if($mode === 'edit')
                    &nbsp;—&nbsp;<a href="{{ route('ext.opencart.product-groups.products', [$setting->id, $group->id]) }}">View Products</a>
                @endif
            </div>

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">
                    {{ $mode === 'create' ? 'Create Product Group' : 'Save Product Group' }}
                </button>
            </div>
        </form>
    </div>
    <script>
        function initListPicker(config) {
            var items = config.items;
            var selectedIds = config.selectedIds;
            var searchEl = config.searchEl;
            var dropdownEl = config.dropdownEl;
            var listEl = config.listEl;
            var fieldName = config.fieldName;
            var selected = new Map();

            selectedIds.forEach(function(id) {
                var item = items.find(function(i) { return i.id === id; });
                if (item) selected.set(id, item.name);
            });

            function render() {
                listEl.textContent = '';
                if (selected.size === 0) {
                    var emptyDiv = document.createElement('div');
                    emptyDiv.style.cssText = 'padding:8px; color:var(--text-muted); font-size:13px;';
                    emptyDiv.textContent = 'None selected';
                    listEl.appendChild(emptyDiv);
                    return;
                }
                selected.forEach(function(name, id) {
                    var row = document.createElement('div');
                    row.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:4px 8px;';
                    var span = document.createElement('span');
                    span.textContent = name;
                    row.appendChild(span);
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = '\u00D7';
                    btn.style.cssText = 'background:none; border:none; cursor:pointer; font-size:18px; color:var(--text-muted); padding:0 4px; line-height:1;';
                    btn.addEventListener('click', function() { selected.delete(id); render(); });
                    row.appendChild(btn);
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = fieldName;
                    inp.value = id;
                    row.appendChild(inp);
                    listEl.appendChild(row);
                });
            }

            function showDropdown(filter) {
                var lower = filter.toLowerCase();
                var filtered = items.filter(function(i) {
                    return !selected.has(i.id) && i.name.toLowerCase().indexOf(lower) !== -1;
                });
                if (filtered.length === 0 || filter === '') {
                    dropdownEl.style.display = 'none';
                    return;
                }
                dropdownEl.textContent = '';
                filtered.slice(0, 50).forEach(function(item) {
                    var div = document.createElement('div');
                    div.textContent = item.name;
                    div.style.cssText = 'padding:6px 10px; cursor:pointer;';
                    div.addEventListener('mouseenter', function() { div.style.background = 'var(--surface-hover)'; });
                    div.addEventListener('mouseleave', function() { div.style.background = ''; });
                    div.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selected.set(item.id, item.name);
                        searchEl.value = '';
                        dropdownEl.style.display = 'none';
                        render();
                    });
                    dropdownEl.appendChild(div);
                });
                dropdownEl.style.display = 'block';
            }

            searchEl.addEventListener('input', function() { showDropdown(searchEl.value.trim()); });
            searchEl.addEventListener('focus', function() { if (searchEl.value.trim()) showDropdown(searchEl.value.trim()); });
            searchEl.addEventListener('blur', function() { setTimeout(function() { dropdownEl.style.display = 'none'; }, 150); });

            render();
        }

        initListPicker({
            items: @json($catalogCategories->map(fn($c) => ['id' => (int)$c->category_id, 'name' => $c->name])),
            selectedIds: @json($selectedCatIds),
            searchEl: document.getElementById('catSearch'),
            dropdownEl: document.getElementById('catDropdown'),
            listEl: document.getElementById('catSelected'),
            fieldName: 'catalog_category_ids[]'
        });

        initListPicker({
            items: @json(($manufacturers ?? collect())->map(fn($m) => ['id' => (int)$m->manufacturer_id, 'name' => $m->name])),
            selectedIds: @json($selectedMfgIds),
            searchEl: document.getElementById('mfgSearch'),
            dropdownEl: document.getElementById('mfgDropdown'),
            listEl: document.getElementById('mfgSelected'),
            fieldName: 'manufacturer_ids[]'
        });
    </script>
@endsection
