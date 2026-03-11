@extends('layouts.app')
@section('breadcrumb', 'Catalog / Options')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Options</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('options.create') }}">Add Option</a>
            <button class="btn danger" type="submit" form="option-bulk-form" id="option-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('options.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search option">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif

    <form id="option-bulk-form" method="POST" action="{{ route('options.bulk_delete') }}" onsubmit="return window.__confirmOptionBulkDelete(event);">
        @csrf
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="option-select-all" title="Select all"></th>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:160px;">Type</th>
            <th style="width:120px;">Sort</th>
            <th style="width:180px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($options as $o)
            <tr>
                <td><input type="checkbox" class="option-row-check" name="ids[]" value="{{ (int)$o->option_id }}" form="option-bulk-form"></td>
                <td>{{ (int)$o->option_id }}</td>
                <td>{{ $o->name }}</td>
                <td>{{ $o->type }}</td>
                <td>{{ (int)$o->sort_order }}</td>
                <td class="d-flex gap-8 items-center">
                    <a class="btn small" href="{{ route('options.edit', $o->option_id) }}">Edit</a>
                    <form method="POST" action="{{ route('options.destroy', $o->option_id) }}" data-confirm="Delete this option? This cannot be undone.">
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

    <div class="mt-16">
        {{ $options->onEachSide(1)->links('vendor.pagination.simple') }}

        @if(method_exists($options, 'total') && $options->total() > 0)
            <div class="mt-12 text-sm text-muted">
                Showing {{ $options->firstItem() }} to {{ $options->lastItem() }} of {{ $options->total() }} results
            </div>
        @endif
    </div>
</div>

<script>
    (function () {
        var selectAll = document.getElementById('option-select-all');
        var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.option-row-check')); };

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

        window.__confirmOptionBulkDelete = function (e) {
            var form = e.target;
            if (form._confirmed) { form._confirmed = false; return true; }
            var selected = checks().filter(function (b) { return b.checked; }).length;
            if (selected === 0) {
                showFlashError('Please select at least one option.');
                return false;
            }
            e.preventDefault();
            confirmModal('Delete selected options? This cannot be undone.').then(function (ok) {
                if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
            });
            return false;
        };

        updateSelectAllState();
    })();
</script>
@endsection
