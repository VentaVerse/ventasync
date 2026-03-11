@extends('layouts.app')
@section('breadcrumb', 'Catalog / Options / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Option #{{ (int)$option->option_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('options.index') }}">Back</a>
            <button class="btn" type="submit" form="option-form">Save</button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert error">
            <ul style="margin:0; padding-left:18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="option-form" method="POST" action="{{ route('options.update', $option->option_id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $option->name) }}">
            </div>

            <div>
                <label class="required">Type</label>
                <select class="input" name="type">
                    @foreach(['select','radio','checkbox','text','textarea','file','date','time','datetime'] as $t)
                        <option value="{{ $t }}" {{ old('type', $option->type)===$t?'selected':'' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="required">Sort Order</label>
                <input class="input" name="sort_order" type="number" value="{{ old('sort_order', (int)$option->sort_order) }}">
            </div>
        </div>

        <hr style="margin:18px 0; opacity:.25;">


<div id="values-section">

    <h3 class="section-title">Values</h3>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:140px;">Sort</th>
            <th style="width:140px;">Actions</th>
        </tr>
        </thead>
        <tbody id="option-values-body">
        @foreach($values as $i => $v)
            <tr>
                <td>{{ (int)$v->option_value_id }}</td>
                <td>
                    <input type="hidden" name="values[{{ $i }}][option_value_id]" value="{{ (int)$v->option_value_id }}">
                    <input class="input" name="values[{{ $i }}][name]" value="{{ old('values.'.$i.'.name', $v->name) }}">
                </td>
                <td>
                    <input class="input" type="number" name="values[{{ $i }}][sort_order]" value="{{ old('values.'.$i.'.sort_order', (int)$v->sort_order) }}">
                </td>
                <td class="actions">
                    <button class="btn danger small" type="button" data-confirm="Delete this value?" data-confirm-submit="del-ov-{{ (int)$v->option_value_id }}">Delete</button>
                </td>
            </tr>
        @endforeach

        @php($oldNew = old('new_values', []))
        @foreach($oldNew as $i => $row)
            <tr data-new-row="1">
                <td>new</td>
                <td><input class="input" name="new_values[{{ $i }}][name]" value="{{ $row['name'] ?? '' }}" placeholder="Add new value"></td>
                <td><input class="input" type="number" name="new_values[{{ $i }}][sort_order]" value="{{ $row['sort_order'] ?? 0 }}"></td>
                <td>
                    <button class="btn danger small" type="button" data-remove-row="1">Remove</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    {{-- Separate forms for deleting existing values (avoid nested forms) --}}
    @foreach($values as $v)
        <form id="del-ov-{{ (int)$v->option_value_id }}" method="POST" action="{{ route('options.values.destroy', [$option->option_id, $v->option_value_id]) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endforeach

    <div id="option-values-controls" class="mt-12 d-flex gap-10 items-center">
        <button class="btn secondary small" type="button" id="add-option-value-btn">Add Value</button>
        <span class="text-secondary text-sm">Add as many values as you need.</span>
    </div>

    <script>
    (function() {
        const supports = new Set(['select','radio','checkbox']);
        const typeEl = document.querySelector('select[name="type"]');
        const valuesSection = document.getElementById('values-section');
        const body = document.getElementById('option-values-body');
        const addBtn = document.getElementById('add-option-value-btn');

        function updateVisibility() {
            const t = (typeEl && typeEl.value) ? typeEl.value : '';
            if (!valuesSection) return;
            valuesSection.style.display = supports.has(t) ? 'block' : 'none';
        }

        function rowHtml(i, nameVal = '', sortVal = 0) {
            const esc = (v) => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            return `
            <tr data-new-row="1">
                <td>new</td>
                <td><input class="input" name="new_values[${i}][name]" value="${esc(nameVal)}" placeholder="Add new value"></td>
                <td><input class="input" type="number" name="new_values[${i}][sort_order]" value="${esc(sortVal)}"></td>
                <td>
                    <button class="btn danger small" type="button" data-remove-row="1">Remove</button>
                </td>
            </tr>`;
        }

        function nextIndex() {
            let max = -1;
            document.querySelectorAll('input[name^="new_values["]').forEach(inp => {
                const m = inp.name.match(/^new_values\[(\d+)\]\[name\]$/);
                if (m) max = Math.max(max, parseInt(m[1], 10));
            });
            return max + 1;
        }

        function addRow(nameVal = '', sortVal = 0) {
            const i = nextIndex();
            body.insertAdjacentHTML('beforeend', rowHtml(i, nameVal, sortVal));
        }

        if (addBtn) addBtn.addEventListener('click', () => addRow());

        if (body) body.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove-row="1"]');
            if (!btn) return;
            const tr = btn.closest('tr');
            if (tr) tr.remove();
        });

        if (typeEl) typeEl.addEventListener('change', updateVisibility);

        updateVisibility();
    })();
    </script>

</div>

        {{-- Save button moved to page header (top-right) --}}
    </form>
</div>
@endsection
