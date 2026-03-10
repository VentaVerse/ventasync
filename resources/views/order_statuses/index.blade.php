@extends('layouts.app')
@section('breadcrumb', 'Sales / Order Statuses')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Order Statuses</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('order_statuses.create') }}">Add Order Status</a>
            <button class="btn danger" type="submit" form="os-bulk-form" id="os-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('order_statuses.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search order status">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <form id="os-bulk-form" method="POST" action="{{ route('order_statuses.bulk') }}" onsubmit="return window.__confirmOsBulkDelete(event);">
        @csrf
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="os-select-all" title="Select all"></th>
                <th style="width:90px;">ID</th>
                <th>Name</th>
                <th style="width:120px;">Subtract Stock</th>
                <th style="width:120px;">Add Revenue</th>
                <th style="width:160px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            @foreach($statuses as $s)
                <tr>
                    <td><input type="checkbox" class="os-row-check" name="ids[]" value="{{ (int)$s->order_status_id }}" form="os-bulk-form"></td>
                    <td>{{ $s->order_status_id }}</td>
                    <td>{{ $s->name }}</td>
                    <td>
                        @if($s->subtract_stock)
                            <span class="badge success">Yes</span>
                        @else
                            <span class="badge secondary">No</span>
                        @endif
                    </td>
                    <td>
                        @if($s->add_revenue)
                            <span class="badge success">Yes</span>
                        @else
                            <span class="badge secondary">No</span>
                        @endif
                    </td>
                    <td class="d-flex gap-8 items-center">
                        <a class="btn small" href="{{ route('order_statuses.edit', $s->order_status_id) }}">Edit</a>
                        <form method="POST" action="{{ route('order_statuses.destroy', $s->order_status_id) }}" data-confirm="Delete this order status?">
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
            var selectAll = document.getElementById('os-select-all');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.os-row-check')); };

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

            window.__confirmOsBulkDelete = function (e) {
                var form = e.target;
                if (form._confirmed) { form._confirmed = false; return true; }
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    showFlashError('Please select at least one order status.');
                    return false;
                }
                e.preventDefault();
                confirmModal('Delete selected order statuses? This cannot be undone.').then(function (ok) {
                    if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
                });
                return false;
            };

            updateSelectAllState();
        })();
    </script>

    <div class="mt-16">
        {{ $statuses->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
