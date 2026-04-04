@extends('layouts.app')
@section('title', $setting->store_name . ' Product Groups')
@section('breadcrumb', 'Marketplace / ' . $setting->store_name . ' / Product Groups')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $setting->store_name }} Product Groups</h2>
            <div class="text-muted text-sm">Group products by category/manufacturer, then push to Venta.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.venta.product-groups.create', $setting->id) }}">Add Product Group</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Product Group</th>
                    <th style="width:100px;">Products</th>
                    <th style="width:220px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($groups as $g)
                    <tr>
                        <td>{{ $g->id }}</td>
                        <td class="font-bold">{{ $g->name }}</td>
                        <td>{{ $productCounts[$g->id] ?? 0 }}</td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('ext.venta.product-groups.products', [$setting->id, $g->id]) }}">Products</a>
                                <a class="btn small secondary" href="{{ route('ext.venta.product-groups.edit', [$setting->id, $g->id]) }}">Edit</a>
                                <form method="POST" action="{{ route('ext.venta.product-groups.destroy', [$setting->id, $g->id]) }}" style="margin:0;" data-confirm="Delete this product group?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-muted">No product groups yet. Click <strong>Add Product Group</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
