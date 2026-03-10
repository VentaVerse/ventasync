@extends('layouts.app')
@section('breadcrumb', 'Catalog / Categories')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Categories</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('categories.create') }}">Add Category</a>

            <select class="input" name="action" form="category-bulk-form" style="width:180px;">
                <option value="">-- Action --</option>
                <option value="enable">Enable</option>
                <option value="disable">Disable</option>
            </select>
            <button class="btn" type="submit" form="category-bulk-form" id="category-apply-btn">Apply To Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('categories.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search category">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <form id="category-bulk-form" method="POST" action="{{ route('categories.bulk') }}" onsubmit="return window.__confirmCategoryBulkAction(event);">
        @csrf
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="category-select-all" title="Select all"></th>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:220px;">Parent</th>
            <th style="width:140px;">Sort</th>
            <th style="width:110px;">Status</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($categories as $c)
            <tr>
                <td>
                    <input type="checkbox" class="category-row-check" name="ids[]" value="{{ (int)$c->category_id }}" form="category-bulk-form">
                </td>
                <td>{{ $c->category_id }}</td>
                <td>{{ $c->name ?? '-' }}</td>
                <td>{{ $c->parent_name ?? '-' }}</td>
                <td>{{ (int)$c->sort_order }}</td>
                <td>{{ (int)$c->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                <td class="d-flex gap-8 items-center">
                    <a class="btn small" href="{{ route('categories.edit', $c->category_id) }}">Edit</a>
                    <form method="POST" action="{{ route('categories.destroy', $c->category_id) }}" data-confirm="Delete this category?">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <script>
        (function () {
            var selectAll = document.getElementById('category-select-all');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.category-row-check')); };
            var actionSelect = document.querySelector('select[name="action"][form="category-bulk-form"]');

            function updateSelectAllState() {
                var boxes = checks();
                if (!selectAll) return;
                if (boxes.length === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    return;
                }
                var checkedCount = boxes.filter(function (b) { return b.checked; }).length;
                selectAll.checked = checkedCount === boxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checks().forEach(function (b) { b.checked = selectAll.checked; });
                    updateSelectAllState();
                });
            }

            checks().forEach(function (b) {
                b.addEventListener('change', updateSelectAllState);
            });

            window.__confirmCategoryBulkAction = function (e) {
                var form = e.target;
                if (form._confirmed) { form._confirmed = false; return true; }
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    showFlashError('Please select at least one category.');
                    return false;
                }
                var action = actionSelect ? (actionSelect.value || '') : '';
                if (!action) {
                    showFlashError('Please choose an action.');
                    return false;
                }
                e.preventDefault();
                confirmModal('Apply "' + action + '" to selected categories?').then(function (ok) {
                    if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
                });
                return false;
            };

            updateSelectAllState();
        })();
    </script>

    <div class="mt-16">
        {{ $categories->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
