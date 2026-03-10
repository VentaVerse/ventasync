@extends('layouts.app')
@section('breadcrumb', 'Purchasing / Vendors')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Vendors</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('vendors.create') }}">Add Vendor</a>
            <button class="btn danger" type="submit" form="vendor-bulk-form" id="vendor-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('vendors.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search by name, contact, or email">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <form id="vendor-bulk-form" method="POST" action="{{ route('vendors.bulk_delete') }}" onsubmit="return window.__confirmVendorBulkDelete(event);">
        @csrf
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="vendor-select-all" title="Select all"></th>
            <th style="width:70px;">ID</th>
            <th>Name</th>
            <th>Contact Person</th>
            <th>Email</th>
            <th>Phone</th>
            <th style="width:80px;">Currency</th>
            <th style="width:80px;">Status</th>
            <th style="width:100px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($vendors as $v)
            <tr>
                <td><input type="checkbox" class="vendor-row-check" name="ids[]" value="{{ $v->id }}" form="vendor-bulk-form"></td>
                <td>{{ $v->id }}</td>
                <td>{{ $v->name }}</td>
                <td>{{ $v->contact_person ?? '—' }}</td>
                <td>{{ $v->email ?? '—' }}</td>
                <td>{{ $v->phone ?? '—' }}</td>
                <td>{{ $v->currency_code }}</td>
                <td><span class="badge {{ $v->status ? 'green' : 'gray' }}">{{ $v->status ? 'Active' : 'Inactive' }}</span></td>
                <td>
                    <a class="btn small" href="{{ route('vendors.edit', $v->id) }}">Edit</a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <script>
        (function () {
            var selectAll = document.getElementById('vendor-select-all');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.vendor-row-check')); };

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

            window.__confirmVendorBulkDelete = function (e) {
                var form = e.target;
                if (form._confirmed) { form._confirmed = false; return true; }
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    showFlashError('Please select at least one vendor.');
                    return false;
                }
                e.preventDefault();
                confirmModal('Delete selected vendors? This cannot be undone.').then(function (ok) {
                    if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
                });
                return false;
            };

            updateSelectAllState();
        })();
    </script>

    <div class="mt-16">
        {{ $vendors->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
