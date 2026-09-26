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
        // sort_by is the select's carrier for the same pair; a header
        // click must not leave a stale copy of it in the URL.
        request()->except('sort', 'dir', 'sort_by'),
        ['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']
    ));

    // The arrow is drawn only on the column actually in force, so the header
    // row reads as one sorted column rather than six competing controls.
    $sortArrow = fn (string $col) => $sort === $col
        ? ($dir === 'asc' ? 'chevron-up' : 'chevron-down')
        : null;
@endphp

@section('content')
    {{-- The delete's answer, and every other redirect-with-message, lands here (2026-09-07: a refused delete was invisible). --}}
    @include('partials.flash')
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

        {{-- The sort, as a control the phone can reach: this table
             scrolls sideways there, so the header you want is usually
             off-screen (the owner, 2026-09-04). One select carries field and
             direction as one decision, and it doubles as the carrier that
             keeps the sort through a filter submit. --}}
        <label class="x-sr" for="products-sort">Sort products</label>
        @php $sortChoices = [
            'product_id:desc' => 'Newest first',
            'product_id:asc' => 'Oldest first',
            'updated:desc' => 'Recently updated',
            'name:asc' => 'Name, A to Z',
            'name:desc' => 'Name, Z to A',
            'price:asc' => 'Price, low to high',
            'price:desc' => 'Price, high to low',
            'quantity:asc' => 'Stock, low to high',
            'quantity:desc' => 'Stock, high to low',
        ]; @endphp
        <x-ui.select id="products-sort" name="sort_by" class="x-filters__select--narrow" data-autosubmit>
            {{-- A header can sort by a pair this list does not carry
                 (Status). The select must say so rather than silently
                 showing Newest first over a differently-ordered table. --}}
            @unless(array_key_exists($sort . ':' . $dir, $sortChoices))
                <option value="{{ $sort }}:{{ $dir }}" selected>Sorted by column</option>
            @endunless
            @foreach($sortChoices as $value => $label)
                <option value="{{ $value }}" @selected($value === $sort . ':' . $dir)>{{ $label }}</option>
            @endforeach
        </x-ui.select>

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
                    {{-- Selection is page-scoped and never said so: "50
                         selected" out of 2,081 across 42 pages reads as the
                         whole catalog. --}}
                    <span class="text-ink-3 font-normal"
                          x-show="selected.length === ids.length && ids.length > 0">on this page</span>
                </span>
                <div class="ml-auto flex gap-1.5">
                    <button type="button" class="bl-btn bl-btn-sm"
                            @click="$refs.bulkAction.value = 'enable'; $refs.bulkForm.requestSubmit()">Enable</button>
                    <button type="button" class="bl-btn bl-btn-sm"
                            @click="$refs.bulkAction.value = 'disable'; $refs.bulkForm.requestSubmit()">Disable</button>
                    {{-- The dialog names the count. Without it, "Delete the
                         selected products?" reads identically whether you
                         ticked one row or hit Select all and took 50, and
                         Select all is one click away from this button. Bound
                         with :data-confirm so it follows the selection. --}}
                    <button type="button" class="bl-btn bl-btn-sm x-btn--danger"
                            @click="$refs.bulkAction.value = 'delete'"
                            :data-confirm="'Delete ' + selected.length + (selected.length === 1 ? ' product' : ' products') + '? This cannot be undone. Anything listed on a store stays there; only VentaSync\'s record is removed.'"
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
                                <th scope="col" class="w-9">
                                    <input type="checkbox" class="bl-check"
                                           :checked="ids.length > 0 && selected.length === ids.length"
                                           x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                                           @change="selected = $event.target.checked ? ids.slice() : []"
                                           aria-label="Select all products on this page">
                                </th>
                            @endif
                            <th scope="col" class="w-16">
                                <a class="bl-sort" href="{{ $sortUrl('product_id') }}">
                                    ID
                                    @if($sortArrow('product_id'))<x-ui.icon :name="$sortArrow('product_id')" size="12" />@endif
                                </a>
                            </th>
                            <th scope="col">
                                <a class="bl-sort" href="{{ $sortUrl('name') }}">
                                    Product
                                    @if($sortArrow('name'))<x-ui.icon :name="$sortArrow('name')" size="12" />@endif
                                </a>
                            </th>
                            <th scope="col" class="num">
                                <a class="bl-sort" href="{{ $sortUrl('quantity') }}">
                                    On hand
                                    @if($sortArrow('quantity'))<x-ui.icon :name="$sortArrow('quantity')" size="12" />@endif
                                </a>
                            </th>
                            <th scope="col" class="num">
                                <a class="bl-sort" href="{{ $sortUrl('price') }}">
                                    Price
                                    @if($sortArrow('price'))<x-ui.icon :name="$sortArrow('price')" size="12" />@endif
                                </a>
                            </th>
                            <th scope="col">
                                <a class="bl-sort" href="{{ $sortUrl('status') }}">
                                    Status
                                    @if($sortArrow('status'))<x-ui.icon :name="$sortArrow('status')" size="12" />@endif
                                </a>
                            </th>
                            <th scope="col" class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $p)
                            @php
                                $img = trim((string) ($p->image ?? ''));
                                $thumbSrc = $img !== '' ? \App\Services\Media\ImageCache::url($img) : '';
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
                                        <input type="checkbox" class="bl-check" name="ids[]" data-row-check
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
                                    {{-- The shared row menu, whose panel is
                                         fixed-positioned and placed by script,
                                         so the scroll box around this table
                                         cannot clip it. This was the last list
                                         on an absolutely positioned dropdown:
                                         on a one-row result (a search) the
                                         menu opened below the box's edge and
                                         was cut off (the owner, 2026-08-27). --}}
                                    <x-ui.menu label="Product {{ $p->product_id }} actions">
                                        <a class="x-menu__item" href="{{ route('products.sales', $p->product_id) }}?back={{ $backUrl }}">Sales history</a>
                                        <a class="x-menu__item" href="{{ route('products.stock_history', $p->product_id) }}?back={{ $backUrl }}">Stock history</a>
                                        @if($canManageProducts)
                                            <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit</a>
                                            <button type="button" class="x-menu__item x-menu__item--danger"
                                                    data-confirm="Delete {{ $p->name }}? This cannot be undone.{{ (($channelNote ?? [])[(int) $p->product_id] ?? '') !== '' ? ' ' . $channelNote[(int) $p->product_id] : '' }}"
                                                    data-confirm-submit="prod-del-{{ $p->product_id }}">Delete</button>
                                        @endif
                                    </x-ui.menu>
                                    @if($canManageProducts)
                                        <form id="prod-del-{{ $p->product_id }}" method="POST"
                                              action="{{ route('products.destroy', $p->product_id) }}" class="sr-only">
                                            @csrf
                                            @method('DELETE')
                                        </form>
                                    @endif
                                </td>
                            </tr>

                            {{-- THE VARIATIONS, UNDER THEIR PARENT.
                                 ------------------------------------------------------------
                                 The legacy list showed these and the redesign dropped them,
                                 keeping the query that loads them - which is why the data was
                                 still here, batched, waiting. A parent that says "2 variations"
                                 makes you open the product to learn what they are and how many
                                 of each you hold, which is the question the list exists to
                                 answer.

                                 Always shown rather than behind a disclosure: only 64 of 2,081
                                 products carry variations at all, the median is 3 and the most
                                 any one has is 15 - so this costs a few rows on the pages that
                                 have any and nothing at all on the rest.

                                 The image is the variation's OWN, and not the parent's as a
                                 fallback: the parent sits directly above, so repeating its
                                 photograph down the variations would be the same picture four
                                 times in the column whose job is telling them apart. That slot
                                 existed in the legacy row too and was hardcoded null in the
                                 controller, so it never once rendered - there was no
                                 per-variation image to put in it until now. --}}
                            @foreach($p->option_rows ?? [] as $or)
                                @php
                                    $orImg = trim((string) ($or->option_image ?? ''));
                                    $orSrc = $orImg !== '' ? \App\Services\Media\ImageCache::url($orImg) : '';
                                    $orQty = (int) ($or->quantity ?? 0);
                                    $orTone = $orQty < 0 ? 'text-bad' : ($orQty === 0 ? 'text-attn' : '');
                                @endphp
                                <tr class="bl-varrow">
                                    @if($canManageProducts)<td></td>@endif
                                    <td></td>
                                    <td>
                                        <div class="flex items-center gap-2.5 bl-varrow__body">
                                            <span class="bl-thumb bl-thumb--sm">
                                                None
                                                @if($orSrc)
                                                    <img src="{{ $orSrc }}" alt="" data-thumb loading="lazy" decoding="async">
                                                @endif
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block truncate">{{ $or->option_value_name ?: '-' }}</span>
                                                <span class="flex flex-wrap items-center gap-x-2.5 text-[12px] text-ink-3">
                                                    <span class="bl-mono">{{ $or->sku ?: '-' }}</span>
                                                    @if(!empty($or->option_name))
                                                        <span>{{ $or->option_name }}</span>
                                                    @endif
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="num {{ $orTone }}">{{ number_format($orQty) }}</td>
                                    <td class="num">{{ \App\Support\Money::base((float) ($or->absolute_price ?? 0)) }}</td>
                                    {{-- The Status column was empty on these rows. A variation
                                         can be withdrawn on its own now, so the column that
                                         answers "is this on sale" has something to say about
                                         them. Read-only here: the edit form is where it is
                                         changed, the same as the product's own status. --}}
                                    <td>
                                        @if((int) ($or->status ?? 1) === 1)
                                            <span class="bl-state bl-state-good"><i></i>Enabled</span>
                                        @else
                                            <span class="bl-state bl-state-off"><i></i>Disabled</span>
                                        @endif
                                    </td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pager :paginator="$products" />

        @endif
    </div>
@endsection
