@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Product Groups')

@section('title', 'Lazada Product Groups')

@section('content')
    <div class="page-header">
        <div>
            <h2>Lazada Product Groups</h2>
            <div class="text-muted text-sm">Group products and mass-map to Lazada category, brand &amp; attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.lazada.product-groups.create') }}">Add Product Group</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Product Group</th>
                    <th style="width:80px;">Products</th>
                    <th>Lazada Category</th>
                    <th style="width:100px;">Markup</th>
                    <th style="width:220px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($groups as $g)
                    @php $pCount = $productCounts->get($g->id, 0); @endphp
                    <tr>
                        <td>{{ $g->id }}</td>
                        <td class="font-bold">{{ $g->name }}</td>
                        <td>
                            <a href="{{ route('ext.lazada.product-groups.products', $g->id) }}">{{ $pCount }}</a>
                        </td>
                        <td>
                            @if($g->lazada_category_id)
                                {{ $lazadaCategoryNames->get($g->lazada_category_id, $g->lazada_category_id) }}
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $parts = [];
                                if (!empty($g->markup_fixed)) $parts[] = '+' . number_format((float)$g->markup_fixed, 2);
                                if (!empty($g->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$g->markup_percent, 2), '0'), '.') . '%';
                            @endphp
                            @if(!empty($parts))
                                {{ implode(' & ', $parts) }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('ext.lazada.product-groups.products', $g->id) }}">Products</a>
                                <a class="btn small secondary" href="{{ route('ext.lazada.product-groups.edit', $g->id) }}">Edit</a>

                                <form method="POST" action="{{ route('ext.lazada.product-groups.destroy', $g->id) }}" style="margin:0;" data-confirm="Delete product group? Products with no other group links will be removed.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-muted">No product groups yet. Click <strong>Add Product Group</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
