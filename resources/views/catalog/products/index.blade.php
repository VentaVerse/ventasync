@extends('layouts.blotter')
@section('title', 'Products')

@php
    use Illuminate\Support\Str;

    // Phase 4.5 view/manage gating: read-tier sees the list plus the Sales
    // and Stock history satellites; create/edit/delete/bulk actions are
    // manage-tier only. Hardcoded true until that gating lands app-wide,
    // unchanged by the v2.2 interface work.
    $canManageProducts = true;

    $hasFilters = $q !== '' || $status !== '';

    $sortUrl = fn (string $col) => route('products.index', array_merge(
        request()->except('sort', 'dir'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));

    // The arrow is drawn only on the column actually in force, so the header
    // row reads as one sorted column rather than six competing controls.
    $sortArrow = fn (string $col) => $sort === $col
        ? ($dir === 'asc' ? 'chevron-up' : 'chevron-down')
        : null;
@endphp

@section('content')
    {{-- On the shared list anatomy, not a hand-rolled copy of it.

         This page had its own head and its own filter row written in raw
         utilities: a bare flex div with an h1 sized by hand, and inputs on
         .bl-input inside a bare form. Every other list in the application -
         45 of them - uses .x-list-head and .x-filters, so this page was the
         one that drifted, and no amount of work on the shared components was
         ever going to reach it. --}}
    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">Products</h1>
            <p class="x-page-sub">
                {{ number_format($products->total()) }} {{ Str::plural('product', $products->total()) }}
                @if($hasFilters) matching this filter @endif
            </p>
        </div>
        @if($canManageProducts)
            <x-ui.button variant="primary" :href="route('products.create')">
                <x-ui.icon name="plus" size="14" /> New product
            </x-ui.button>
        @endif
    </div>

    <form method="GET" action="{{ route('products.index') }}" id="products-filter" class="x-filters">
        <label class="x-sr" for="products-q">Search products</label>
        <x-ui.input type="search" id="products-q" name="q" :value="$q"
                    placeholder="Search name, SKU, model" class="x-filters__search" />

        <label class="x-sr" for="products-status">Filter by status</label>
        <x-ui.select id="products-status" name="status" class="x-filters__select--narrow" data-autosubmit>
            <option value="">All statuses</option>
            <option value="1" @selected($status === '1')>Enabled</option>
            <option value="0" @selected($status === '0')>Disabled</option>
        </x-ui.select>

        {{-- Keeps the sorted column from being dropped the moment a filter
             is submitted. --}}
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">

        @if($hasFilters)
            <a href="{{ route('products.index') }}" class="x-filters__reset">Clear filters</a>
        @endif
    </form>

    <div x-data="{
            selected: [],
            ids: {{ \Illuminate\Support\Js::from($products->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
        }">

        @if($canManageProducts)
            <form id="product-bulk-form" method="POST" action="{{ route('products.bulk') }}" x-ref="bulkForm">
                @csrf
                <input type="hidden" name="action" x-ref="bulkAction" value="">
            </form>

            {{-- The bulk bar only exists while something is selected, so the
                 page never carries a row of controls that do nothing. --}}
            <div x-show="selected.length > 0" x-cloak
                 class="flex flex-wrap items-center gap-2 border-y border-rule-2 bg-attn-wash px-gutter py-2.5">
                <span class="text-[13px] font-semibold">
                    <span x-text="selected.length"></span> selected
                </span>
                <div class="ml-auto flex gap-1.5">
                    <button type="button" class="bl-btn bl-btn-sm"
                            @click="$refs.bulkAction.value = 'enable'; $refs.bulkForm.requestSubmit()">Enable</button>
                    <button type="button" class="bl-btn bl-btn-sm"
                            @click="$refs.bulkAction.value = 'disable'; $refs.bulkForm.requestSubmit()">Disable</button>
                    <button type="button" class="bl-btn bl-btn-sm x-btn--danger"
                            @click="$refs.bulkAction.value = 'delete'"
                            data-confirm="Delete the selected products? This cannot be undone."
                            data-confirm-submit="product-bulk-form">Delete</button>
                </div>
            </div>
        @endif

        @if($products->isEmpty())
            <div class="border-t border-rule-2 bg-sheet px-gutter py-14 text-center">
                <p class="text-[15px] font-semibold">
                    {{ $hasFilters ? 'No products match those filters' : 'No products yet' }}
                </p>
                <p class="mx-auto mt-1 max-w-[46ch] text-[13px] text-ink-2">
                    {{ $hasFilters
                        ? 'Try clearing the search or choosing a different status.'
                        : 'Add your first product to start building the catalog.' }}
                </p>
                @if($canManageProducts && !$hasFilters)
                    <a href="{{ route('products.create') }}" class="bl-btn bl-btn-primary mt-4">New product</a>
                @endif
            </div>
        @else
            <div class="bl-scroll">
                <table class="bl-table">
                    <thead>
                        <tr>
                            @if($canManageProducts)
                                <th class="w-9">
                                    <input type="checkbox" class="bl-check"
                                           :checked="ids.length > 0 && selected.length === ids.length"
                                           x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                                           @change="selected = $event.target.checked ? ids.slice() : []"
                                           aria-label="Select all products on this page">
                                </th>
                            @endif
                            <th class="w-16">
                                <a class="bl-sort" href="{{ $sortUrl('product_id') }}">
                                    ID
                                    @if($sortArrow('product_id'))<x-ui.icon :name="$sortArrow('product_id')" size="12" />@endif
                                </a>
                            </th>
                            <th>
                                <a class="bl-sort" href="{{ $sortUrl('name') }}">
                                    Product
                                    @if($sortArrow('name'))<x-ui.icon :name="$sortArrow('name')" size="12" />@endif
                                </a>
                            </th>
                            <th class="num">
                                <a class="bl-sort" href="{{ $sortUrl('quantity') }}">
                                    On hand
                                    @if($sortArrow('quantity'))<x-ui.icon :name="$sortArrow('quantity')" size="12" />@endif
                                </a>
                            </th>
                            <th class="num">
                                <a class="bl-sort" href="{{ $sortUrl('price') }}">
                                    Price
                                    @if($sortArrow('price'))<x-ui.icon :name="$sortArrow('price')" size="12" />@endif
                                </a>
                            </th>
                            <th>
                                <a class="bl-sort" href="{{ $sortUrl('status') }}">
                                    Status
                                    @if($sortArrow('status'))<x-ui.icon :name="$sortArrow('status')" size="12" />@endif
                                </a>
                            </th>
                            <th class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $p)
                            @php
                                $img = trim((string) ($p->image ?? ''));
                                $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                                $variationCount = count($p->option_rows ?? []);
                                $backUrl = urlencode(request()->fullUrl());
                                $qty = (int) $p->quantity;
                                // Stock is the one figure here that can be
                                // wrong in a way that costs money, so it is
                                // the one that carries a state colour.
                                $qtyTone = $qty < 0 ? 'text-bad' : ($qty === 0 ? 'text-attn' : '');
                            @endphp
                            <tr>
                                @if($canManageProducts)
                                    <td>
                                        <input type="checkbox" class="bl-check" name="ids[]"
                                               value="{{ (string) $p->product_id }}"
                                               form="product-bulk-form" x-model="selected"
                                               aria-label="Select {{ $p->name }}">
                                    </td>
                                @endif
                                <td class="bl-mono">{{ $p->product_id }}</td>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        {{-- The placeholder is always present and the image
                                             covers it. A path this installation does not hold
                                             (images can live on a separate storefront) is
                                             removed by thumb-fallback.js, revealing it. --}}
                                        <span class="bl-thumb">
                                            None
                                            @if($thumbSrc)
                                                <img src="{{ $thumbSrc }}" alt="" data-thumb loading="lazy" decoding="async">
                                            @endif
                                        </span>
                                        <span class="min-w-0">
                                            @if($canManageProducts)
                                                <a href="{{ route('products.edit', $p->product_id) }}"
                                                   class="block font-semibold text-ink no-underline hover:underline">{{ $p->name ?? '-' }}</a>
                                            @else
                                                <span class="block font-semibold">{{ $p->name ?? '-' }}</span>
                                            @endif
                                            <span class="flex flex-wrap items-center gap-x-2.5 text-[12px] text-ink-3">
                                                <span class="bl-mono">{{ $p->sku ?: '-' }}</span>
                                                @if($variationCount > 0)
                                                    <span>{{ $variationCount }} {{ Str::plural('variation', $variationCount) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td class="num {{ $qtyTone }}">{{ number_format($qty) }}</td>
                                <td class="num">{{ \App\Support\Money::base((float) $p->price) }}</td>
                                <td>
                                    @if((int) $p->status === 1)
                                        <span class="bl-state bl-state-good"><i></i>Enabled</span>
                                    @else
                                        <span class="bl-state bl-state-off"><i></i>Disabled</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                                        <button type="button" @click="open = !open"
                                                :aria-expanded="open ? 'true' : 'false'"
                                                class="bl-btn bl-btn-sm"
                                                aria-label="Product {{ $p->product_id }} actions">
                                            <x-ui.icon name="chevron-down" size="12" />
                                        </button>
                                        <div x-show="open" x-cloak @click.outside="open = false" class="bl-menu">
                                            <a href="{{ route('products.sales', $p->product_id) }}?back={{ $backUrl }}">Sales history</a>
                                            <a href="{{ route('products.stock_history', $p->product_id) }}?back={{ $backUrl }}">Stock history</a>
                                            @if($canManageProducts)
                                                <a href="{{ route('products.edit', $p->product_id) }}">Edit</a>
                                                <button type="button" class="is-danger"
                                                        data-confirm="Delete {{ $p->name }}? This cannot be undone."
                                                        data-confirm-submit="prod-del-{{ $p->product_id }}">Delete</button>
                                            @endif
                                        </div>
                                    </div>
                                    @if($canManageProducts)
                                        <form id="prod-del-{{ $p->product_id }}" method="POST"
                                              action="{{ route('products.destroy', $p->product_id) }}" class="sr-only">
                                            @csrf
                                            @method('DELETE')
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pager :paginator="$products" />

        @endif
    </div>
@endsection
