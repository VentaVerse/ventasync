@extends('layouts.app')
@section('breadcrumb', 'Catalog / Manufacturers')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Manufacturers</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('manufacturers.create') }}">Add Manufacturer</a>
            <button class="btn danger" type="submit" form="manufacturer-bulk-form" id="manufacturer-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('manufacturers.index') }}">
            <div class="ac-wrap" style="position:relative;">
    <input id="manufacturer-search" class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search manufacturer">
    <div id="manufacturer-search-dd" class="ac-dd hidden">
        <ul id="manufacturer-search-list"></ul>
    </div>
</div>
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <form id="manufacturer-bulk-form" method="POST" action="{{ route('manufacturers.bulk_delete') }}" onsubmit="return window.__confirmManufacturerBulkDelete(event);">
        @csrf
    </form>

    <div class="table-wrap">
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
                <td class="d-flex gap-8 items-center">
                    <a class="btn small" href="{{ route('manufacturers.edit', $m->manufacturer_id) }}">Edit</a>
                    <form method="POST" action="{{ route('manufacturers.destroy', $m->manufacturer_id) }}" data-confirm="Delete this manufacturer?">
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

            window.__confirmManufacturerBulkDelete = function (e) {
                var form = e.target;
                if (form._confirmed) { form._confirmed = false; return true; }
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    showFlashError('Please select at least one manufacturer.');
                    return false;
                }
                e.preventDefault();
                confirmModal('Delete selected manufacturers? This cannot be undone.').then(function (ok) {
                    if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
                });
                return false;
            };

            updateSelectAllState();
        })();
    </script>

    <div class="mt-16">
        {{ $manufacturers->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
