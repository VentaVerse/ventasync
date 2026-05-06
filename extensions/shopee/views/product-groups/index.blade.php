@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Product Groups')

@section('title', 'Shopee Product Groups')

@section('content')
    <div class="page-header">
        <div>
            <h2>Shopee Product Groups</h2>
            <div class="text-muted text-sm">Group products and mass-map to Shopee category &amp; attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.shopee.product-groups.create') }}">Add Product Group</a>
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
                    <th>Shopee Category</th>
                    <th>Logistics</th>
                    <th style="width:100px;">Markup</th>
                    <th style="width:220px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($groups as $p)
                    @php $pCount = $productCounts->get($p->id, 0); @endphp
                    <tr>
                        <td>{{ $p->id }}</td>
                        <td class="font-bold">{{ $p->name }}</td>
                        <td>
                            <a href="{{ route('ext.shopee.product-groups.products', $p->id) }}">{{ $pCount }}</a>
                        </td>
                        <td>
                            @if($p->shopee_category_id)
                                {{ $shopeeCategoryNames->get($p->shopee_category_id, $p->shopee_category_id) }}
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td>
                            @php $logCount = count($p->logistic_ids ?? []); @endphp
                            @if($logCount > 0)
                                <span class="badge badge-green">{{ $logCount }} channel{{ $logCount > 1 ? 's' : '' }}</span>
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $parts = [];
                                if (!empty($p->markup_fixed)) $parts[] = '+' . number_format((float)$p->markup_fixed, 2);
                                if (!empty($p->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$p->markup_percent, 2), '0'), '.') . '%';
                            @endphp
                            @if(!empty($parts))
                                {{ implode(' & ', $parts) }}
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-6 items-center text-nowrap">
                                <a class="btn small" href="{{ route('ext.shopee.product-groups.products', $p->id) }}">Products</a>
                                <a class="btn small secondary" href="{{ route('ext.shopee.product-groups.edit', $p->id) }}">Edit</a>

                                <form method="POST" action="{{ route('ext.shopee.product-groups.destroy', $p->id) }}" style="margin:0;" data-confirm="Delete this product group?">
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
