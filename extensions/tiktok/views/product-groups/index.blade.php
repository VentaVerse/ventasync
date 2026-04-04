@extends('layouts.app')
@section('title', 'TikTok Shop Product Groups')
@section('breadcrumb', 'Marketplace / TikTok Shop / Product Groups')

@section('content')
    <div class="page-header">
        <div>
            <h2>TikTok Shop Product Groups</h2>
            <div class="text-muted text-sm">Map ERP products to TikTok categories, then push to TikTok Shop.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.tiktok.product-groups.create') }}">Add Product Group</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Product Group</th>
                    <th>TikTok Category</th>
                    <th>Filter</th>
                    <th style="width:90px;">Markup</th>
                    <th style="width:80px;">Products</th>
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
                        $filterLabel = !empty($filterParts) ? implode(' | ', $filterParts) : 'Manual only';

                        $parts = [];
                        if (!empty($g->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$g->markup_percent, 2), '0'), '.') . '%';
                        if (!empty($g->markup_fixed)) $parts[] = '+' . number_format((float)$g->markup_fixed, 2);
                        $markupLabel = !empty($parts) ? implode(' ', $parts) : '-';
                    @endphp
                    <tr>
                        <td>{{ $g->id }}</td>
                        <td class="font-bold">{{ $g->name }}</td>
                        <td>{{ $ttCategoryNames->get($g->tiktok_category_id) ?? $g->tiktok_category_id }}</td>
                        <td><span class="{{ empty($filterParts) ? 'text-muted' : '' }}">{{ $filterLabel }}</span></td>
                        <td><span class="{{ $markupLabel === '-' ? 'text-muted' : '' }}">{{ $markupLabel }}</span></td>
                        <td>{{ $productCounts[$g->id] ?? 0 }}</td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('ext.tiktok.product-groups.products', $g->id) }}">Products</a>
                                <a class="btn small secondary" href="{{ route('ext.tiktok.product-groups.edit', $g->id) }}">Edit</a>
                                <form method="POST" action="{{ route('ext.tiktok.product-groups.destroy', $g->id) }}" style="margin:0;" data-confirm="Delete this product group?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn small danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">
                            No product groups yet. Click <strong>Add Product Group</strong> to create one.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
