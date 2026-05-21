@extends('layouts.app')
@section('breadcrumb', 'Integrations / ' . $setting->store_name . ' / Product Groups')

@section('content')
@php
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart') ?? false;
@endphp
    <div class="page-header">
        <div>
            <h2>{{ $setting->store_name }} — Product Groups</h2>
            <div class="text-muted text-sm">Group products by category/manufacturer, then push to OpenCart.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('integrations.index') }}">&larr; Back to Integrations</a>
            @if($canManageOpencart)
            <a class="btn" href="{{ route('ext.opencart.product-groups.create', $setting->id) }}">Add Product Group</a>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Product Group</th>
                    <th>Filter</th>
                    <th style="width:100px;">Products</th>
                    <th style="width:220px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($groups as $g)
                    @php
                        $catNames = collect($g->catalog_category_ids ?? [])->map(fn($id) => $categoryNames->get($id))->filter()->values();
                        $mfgNames = collect($g->manufacturer_ids ?? [])->map(fn($id) => $manufacturerNames->get($id))->filter()->values();
                        $filterParts = [];
                        if ($catNames->isNotEmpty()) $filterParts[] = 'Cat: ' . $catNames->implode(', ');
                        if ($mfgNames->isNotEmpty()) $filterParts[] = 'Mfg: ' . $mfgNames->implode(', ');
                        $filterLabel = !empty($filterParts) ? implode(' | ', $filterParts) : 'No filter (manual only)';
                    @endphp
                    <tr>
                        <td>{{ $g->id }}</td>
                        <td class="font-bold">{{ $g->name }}</td>
                        <td>
                            <span class="{{ empty($filterParts) ? 'text-muted' : '' }}">{{ $filterLabel }}</span>
                        </td>
                        <td>{{ $productCounts[$g->id] ?? 0 }}</td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('ext.opencart.product-groups.products', [$setting->id, $g->id]) }}">Products</a>
                                @if($canManageOpencart)
                                <a class="btn small" href="{{ route('ext.opencart.product-groups.edit', [$setting->id, $g->id]) }}">Edit</a>
                                <form method="POST" action="{{ route('ext.opencart.product-groups.destroy', [$setting->id, $g->id]) }}" style="margin:0;" data-confirm="Delete this product group?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted">No product groups yet. Click <strong>Add Product Group</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
