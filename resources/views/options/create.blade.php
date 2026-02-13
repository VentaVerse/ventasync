@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Option</h2>
        <a class="btn secondary" href="{{ route('options.index') }}">Back</a>
    </div>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert error">{{ session('error') }}</div>
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

    <form method="POST" action="{{ route('options.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label class="required">Type</label>
                <select class="input" name="type" required>
                    @foreach(['select','radio','checkbox','text','textarea','file','date','time','datetime'] as $t)
                        <option value="{{ $t }}" {{ old('type','select')===$t?'selected':'' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="required">Sort Order</label>
                <input class="input" name="sort_order" type="number" value="{{ old('sort_order', 0) }}" required>
            </div>
        </div>

        <hr style="margin:18px 0; opacity:.25;">

        
<div id="values-section">

    <h3 style="margin:0 0 10px 0;">Values</h3>

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

    <div id="option-values-controls" style="margin-top:10px; display:flex; gap:10px; align-items:center;">
        <button class="btn secondary small" type="button" id="add-option-value-btn">Add Value</button>
        <span style="color:#64748b; font-size:13px;">Add as many values as you need.</span>
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

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
            <button class="btn" type="submit">Create</button>
        </div>
    </form>
</div>
@endsection
