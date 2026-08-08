@extends('layouts.shell')
@section('title', 'Products')

@section('content')
@php
    // Phase 4.5 view/manage gating: read-tier sees the list plus the Sales
    // and Stock history satellites; create/edit/delete/bulk actions are
    // manage-tier only. Mirrors sales/orders/index-v2.blade.php - hardcoded
    // true until that gating lands app-wide. Same live gap as view_orders (documented separately):
    // view_products is declared in config/permissions.php and in this
    // route's own middleware, but core permissions only reach the
    // `permissions` table via `php artisan permission:sync`, which nothing
    // runs automatically on a fresh install. Not this task's fix.
    $canManageProducts = true;

    $hasFilters = $q !== '' || $status !== '';

    $sortUrl = fn (string $col) => route('products.index', array_merge(
        request()->except('sort', 'dir'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));
@endphp

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Products</h1>
        <p class="x-page-sub">{{ number_format($products->total()) }} {{ Str::plural('product', $products->total()) }}</p>
    </div>
    @if($canManageProducts)
    <x-ui.button variant="primary" :href="route('products.create')">New product</x-ui.button>
    @endif
</div>

<form method="GET" action="{{ route('products.index') }}" class="x-filters" id="products-filter">
    <x-ui.input type="search" name="q" value="{{ $q }}" placeholder="Search name, SKU, model" class="x-filters__search" />
    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit>
            <option value="">All statuses</option>
            <option value="1" @selected($status === '1')>Enabled</option>
            <option value="0" @selected($status === '0')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    {{-- Keeps whatever column the list is sorted by from being dropped the
         moment a filter is submitted - same fix orders/index-v2 carries. --}}
    <input type="hidden" name="sort" value="{{ $sort }}">
    <input type="hidden" name="dir" value="{{ $dir }}">
</form>

<div class="cat-page" x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($products->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
    }">

@if($canManageProducts)
    <form id="product-bulk-form" method="POST" action="{{ route('products.bulk') }}" x-ref="bulkForm">
        @csrf
        <input type="hidden" name="action" x-ref="bulkAction" value="">
    </form>

    <div class="cat-bulkbar" x-show="selected.length > 0" x-cloak>
        <span class="cat-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
        <div class="cat-bulkbar__actions">
            <x-ui.button type="button" size="sm" @click="$refs.bulkAction.value = 'enable'; $refs.bulkForm.requestSubmit()">Enable</x-ui.button>
            <x-ui.button type="button" size="sm" @click="$refs.bulkAction.value = 'disable'; $refs.bulkForm.requestSubmit()">Disable</x-ui.button>
            <x-ui.button type="button" variant="danger" size="sm"
                    @click="$refs.bulkAction.value = 'delete'"
                    data-confirm="Delete the selected products? This cannot be undone."
                    data-confirm-submit="product-bulk-form">Delete</x-ui.button>
        </div>
    </div>
@endif

@if($products->isEmpty())
    <x-ui.empty title="{{ $hasFilters ? 'No products match those filters' : 'No products yet' }}"
                description="{{ $hasFilters ? 'Try clearing the search or choosing a different status.' : 'Add your first product to start building the catalog.' }}">
        @if($canManageProducts && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('products.create')">New product</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageProducts)
            <th class="cat-col-check">
                <input type="checkbox" class="cat-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select all products on this page">
            </th>
            @endif
            <th class="cat-col-id">
                <a class="x-th-sort" href="{{ $sortUrl('product_id') }}">
                    ID
                    @if($sort === 'product_id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th>
                <a class="x-th-sort" href="{{ $sortUrl('name') }}">
                    Product
                    @if($sort === 'name')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="cat-col-qty x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('quantity') }}">
                    Quantity
                    @if($sort === 'quantity')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="cat-col-price x-td-num">
                <a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('price') }}">
                    Price
                    @if($sort === 'price')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="cat-col-stat">
                <a class="x-th-sort" href="{{ $sortUrl('status') }}">
                    Status
                    @if($sort === 'status')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif
                </a>
            </th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
            $variationCount = count($p->option_rows ?? []);
            $backUrl = urlencode(request()->fullUrl());
        @endphp
        <tr>
            @if($canManageProducts)
            <td class="cat-col-check">
                <input type="checkbox" class="cat-check" name="ids[]" value="{{ (string) $p->product_id }}" form="product-bulk-form" x-model="selected">
            </td>
            @endif
            <td class="cat-col-id" data-label="ID"><span class="x-num">{{ $p->product_id }}</span></td>
            <td data-label="Product">
                <div class="co-item">
                    <div class="cat-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" onerror="this.style.display='none';">
                        @endif
                    </div>
                    <div class="co-item__body">
                        @if($canManageProducts)
                            <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $p->name ?? '-' }}</a>
                        @else
                            <span class="co-item__name">{{ $p->name ?? '-' }}</span>
                        @endif
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: '-' }}</span>
                            @if($variationCount > 0)
                                <span class="co-item__var">{{ $variationCount }} {{ Str::plural('variation', $variationCount) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>
            <td class="cat-col-qty x-td-num" data-label="Quantity"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cat-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cat-col-stat" data-label="Status">
                <x-ui.badge :tone="(int) $p->status === 1 ? 'success' : 'neutral'">{{ (int) $p->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>
            <td class="x-td-actions">
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('products.sales', $p->product_id) }}?back={{ $backUrl }}">Sales history</a>
                    <a class="x-menu__item" href="{{ route('products.stock_history', $p->product_id) }}?back={{ $backUrl }}">Stock history</a>
                    @if($canManageProducts)
                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit</a>
                    <div class="x-menu__sep"></div>
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete {{ $p->name }}? This cannot be undone."
                            data-confirm-submit="prod-del-{{ $p->product_id }}">Delete</button>
                    <form id="prod-del-{{ $p->product_id }}" method="POST" action="{{ route('products.destroy', $p->product_id) }}" class="x-sr">
                        @csrf
                        @method('DELETE')
                    </form>
                    @endif
                </x-ui.menu>
            </td>
        </tr>
    @endforeach
</x-ui.table>

<div class="x-pager">
    <span class="x-pager__count x-num">{{ number_format($products->firstItem() ?? 0) }} to {{ number_format($products->lastItem() ?? 0) }} of {{ number_format($products->total()) }}</span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" :href="$products->previousPageUrl()" :disabled="!$products->previousPageUrl()"><x-ui.icon name="chevron-left" size="14" /> Previous</x-ui.button>
        <x-ui.button size="sm" :href="$products->nextPageUrl()" :disabled="!$products->nextPageUrl()">Next <x-ui.icon name="chevron-right" size="14" /></x-ui.button>
    </div>
</div>
@endif
</div>
@endsection
