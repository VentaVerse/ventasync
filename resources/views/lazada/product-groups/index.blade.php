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
            <a class="btn" href="{{ route('lazada.products.index') }}">Lazada Products</a>
            <a class="btn" href="{{ route('lazada.product-groups.create') }}">Add Product Group</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Product Group</th>
                    <th>Filter</th>
                    <th>Lazada Category</th>
                    <th style="width:100px;">Markup</th>
                    <th style="width:80px;">Products</th>
                    <th style="width:180px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($groups as $g)
                    @php
                        $catNames = collect($g->catalog_category_ids ?? [])->map(fn($id) => $categoryNames->get($id))->filter()->values();
                        $mfgNames = collect($g->manufacturer_ids ?? [])->map(fn($id) => $manufacturerNames->get($id))->filter()->values();
                        $filterParts = [];
                        if ($catNames->isNotEmpty()) $filterParts[] = $catNames->implode(', ');
                        if ($mfgNames->isNotEmpty()) $filterParts[] = $mfgNames->implode(', ');
                        $filterLabel = !empty($filterParts) ? implode(' | ', $filterParts) : 'All Products';
                    @endphp
                    <tr>
                        <td>{{ $g->id }}</td>
                        <td class="font-bold">{{ $g->name }}</td>
                        <td>
                            <span class="{{ empty($filterParts) ? 'text-muted' : '' }}">{{ $filterLabel }}</span>
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
                        <td>{{ $productCounts->get($g->id, 0) }}</td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('lazada.product-groups.edit', $g->id) }}">Edit</a>

                                <form method="POST" action="{{ route('lazada.product-groups.destroy', $g->id) }}" style="margin:0;" data-confirm="Delete product group? Products with no other group links will be removed.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">No product groups yet. Click <strong>Add Product Group</strong> to create one.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
