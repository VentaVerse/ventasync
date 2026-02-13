@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Manufacturers</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn" href="{{ route('manufacturers.create') }}">Add Manufacturer</a>
            <button class="btn danger" type="submit" form="manufacturer-bulk-form" id="manufacturer-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('manufacturers.index') }}">
            <div class="ac-wrap" style="position:relative; max-width:420px; width:100%;">
    <input id="manufacturer-search" class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search manufacturer">
    <div id="manufacturer-search-dd" class="ac-dd" style="display:none;">
        <ul id="manufacturer-search-list"></ul>
    </div>
</div>
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <form id="manufacturer-bulk-form" method="POST" action="{{ route('manufacturers.bulk_delete') }}" onsubmit="return window.__confirmManufacturerBulkDelete();">
        @csrf
    </form>

    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="manufacturer-select-all" title="Select all"></th>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:140px;">Sort</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($manufacturers as $m)
            <tr>
                <td><input type="checkbox" class="manufacturer-row-check" name="ids[]" value="{{ (int)$m->manufacturer_id }}" form="manufacturer-bulk-form"></td>
                <td>{{ $m->manufacturer_id }}</td>
                <td>{{ $m->name ?? '' }}</td>
                <td>{{ (int)$m->sort_order }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('manufacturers.edit', $m->manufacturer_id) }}">Edit</a>
                    <form method="POST" action="{{ route('manufacturers.destroy', $m->manufacturer_id) }}" onsubmit="return confirm('Delete this manufacturer?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <script>
        (function () {
            var selectAll = document.getElementById('manufacturer-select-all');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.manufacturer-row-check')); };

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

            window.__confirmManufacturerBulkDelete = function () {
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    alert('Please select at least one manufacturer.');
                    return false;
                }
                return confirm('Delete selected manufacturers? This cannot be undone.');
            };

            updateSelectAllState();
        })();
    </script>

    <div style="margin-top:14px;">
        {{ $manufacturers->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
