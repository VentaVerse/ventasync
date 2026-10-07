@extends('layouts.channel')
@section('title', $group->name . ' Products, ' . $setting->store_name)
@section('breadcrumb', $group->name . ' Products')

@section('content')
@php
    $canManageVenta = auth()->user()?->hasPermission('manage_ventacart/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';
    $returnTo = request()->getRequestUri();

    $q = (string) ($q ?? '');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $hasFilters = $q !== '' || $erpStatus !== 'all' || $syncStatus !== 'all';

    $listUrl = route('ext.ventacart.product-groups.products', [$setting->id, $group->id]);
@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from(collect($products->items())->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">{{ $group->name }}</h1>
        <p class="x-page-sub">
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this product group on {{ $storeName }}
        </p>
    </div>
    <div class="cc-head-actions">
        @if($canManageVenta)
            <form method="POST" action="{{ route('ext.ventacart.product-groups.check', [$setting->id, $group->id]) }}"
                  data-confirm="Check every product in this product group against {{ $storeName }}? Nothing is sent or changed on {{ $storeName }}; the ERP updates its own records to match what it finds.">
                @csrf
                <input type="hidden" name="_return" value="{{ $returnTo }}">
                <x-ui.button type="submit" variant="secondary">Check against {{ $storeName }}</x-ui.button>
            </form>
        @endif
        <x-ui.button variant="secondary" :href="route('ext.ventacart.product-groups.orphans', [$setting->id, $group->id])">
            Unlinked on {{ $storeName }}
        </x-ui.button>
        @if($canManageVenta)
            <x-ui.button :href="route('ext.ventacart.product-groups.edit', [$setting->id, $group->id])">Edit product group</x-ui.button>
        @endif
    </div>
</div>

<form method="GET" action="{{ $listUrl }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search products in this product group" />

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalog status">
            <option value="all" @selected($erpStatus === 'all')>Any catalog status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="sync_status" class="x-input" data-autosubmit aria-label="Filter by push status">
            <option value="all" @selected($syncStatus === 'all')>Pushed or not</option>
            <option value="pushed" @selected($syncStatus === 'pushed')>Pushed</option>
            <option value="pending" @selected($syncStatus === 'pending')>Not pushed yet</option>
            <option value="error" @selected($syncStatus === 'error')>Has an error</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    @if(!empty($orderOptions))
        @include('partials.listing-sort', ['options' => $orderOptions, 'chosen' => $order])
    @endif

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $listUrl }}">Clear</a>
    @endif
</form>

@if($canManageVenta)
@php
    $gf = ['_return' => $returnTo];
    $bulk = ['group' => true,
        'push' => ['action' => route('ext.ventacart.product-groups.push', [$setting->id, $group->id]), 'label' => 'Send to ' . $storeName, 'fields' => $gf, 'confirm' => "Send the selected products to {$storeName} with this product group's settings? Anything already sent is updated in place."],
        'link' => ['action' => route('ext.ventacart.product-groups.check', [$setting->id, $group->id]), 'label' => 'Link IDs', 'fields' => $gf],
        'switch' => [
            ['action' => route('ext.ventacart.listings.bulk_toggle', $setting->id), 'field' => 'product_ids[]', 'verb' => 'unlist', 'codes' => 'active', 'label' => 'Unlist :n on ' . $storeName, 'button' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist the selected products on {$storeName}? Buyers stop seeing them. They can be relisted at any time."],
            ['action' => route('ext.ventacart.listings.bulk_toggle', $setting->id), 'field' => 'product_ids[]', 'verb' => 'relist', 'codes' => 'inactive', 'label' => 'Relist :n on ' . $storeName, 'button' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist the selected products on {$storeName}? Buyers see them again at once."],
        ],
        'delete' => ['action' => route('ext.ventacart.product-groups.bulk-delete-from-ventacart', [$setting->id, $group->id]), 'label' => 'Delete from ' . $storeName, 'fields' => $gf, 'confirm' => "Delete the selected products from {$storeName}? They are removed at the storefront and this cannot be undone."],
        'remove' => ['action' => route('ext.ventacart.product-groups.mass-remove', [$setting->id, $group->id]), 'label' => 'Remove from product group', 'fields' => $gf, 'confirm' => "Take the selected products out of this product group? Nothing is deleted from the catalog, and nothing already live on {$storeName} is touched."],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this product group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalog and push filters.'
                    : 'Edit the product group to pick products from the catalog, or add them one at a time below.'">
        @if($canManageVenta && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.ventacart.product-groups.edit', [$setting->id, $group->id])">Edit product group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
@php $colCount = 8 + ($canManageVenta ? 1 : 0); @endphp
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageVenta)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th scope="col" class="cc-col-id">ID</th>
            <th scope="col">Product</th>
            <th scope="col" class="cc-col-qty x-td-num">Stock</th>
            <th scope="col" class="cc-col-price x-td-num">Price</th>
            <th scope="col" class="cc-col-chanid">VentaCart ID</th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-sync">Push</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? \App\Services\Media\ImageCache::url($img) : null;

            $syncState = $p->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked';
            $hasVentaId = !empty($p->ventacart_product_id) && !$isUnlinked;
            $failed = in_array($syncState, ['error', 'failed'], true);

            $confirmedAt = ($p->last_confirmed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($p->last_confirmed_at)
                : null;


            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $pName = (string) ($rowTitles[(int) $p->product_id] ?? html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $pSku = (string) ($p->sku ?: ($p->model ?: ''));
            $isManual = in_array((int) $p->product_id, $manualIds, true);

            $metaParts = [$pSku !== '' ? $pSku : 'No SKU'];
            if (!empty($p->manufacturer_name)) {
                $metaParts[] = (string) $p->manufacturer_name;
            }
            $metaParts[] = $isManual ? 'added by hand' : 'matched by the product group filter';
            $meta = implode(' / ', $metaParts);
        @endphp
        @include('partials.channel-error-row', ['error' => $rowErrors[(int) $p->product_id] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount, 'productId' => (int) $p->product_id])
        <tr>
            @if($canManageVenta)
            <td class="cc-col-check">
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $p->product_id }}"
                       x-model="selected" aria-label="Select product {{ $p->product_id }}">
            </td>
            @endif

            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $p->product_id }}</span></td>

            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        <span class="co-item__meta">{{ $meta }}</span>

                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ number_format((int) $p->quantity) }}</span></td>

            <td class="cc-col-price x-td-num" data-label="Price">
                <x-money :php="(float) $p->price" />
            </td>

            <td class="cc-col-chanid" data-label="VentaCart ID">
                @if($hasVentaId)
                    <span class="x-num">{{ $p->ventacart_product_id }}</span>
                @else
                    <span class="x-cell-muted">Not pushed</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalog">
                @if((int) $p->status === 1)
                    <span class="x-cell-strong">Enabled</span>
                @else
                    <span class="x-cell-muted">Disabled</span>
                @endif
            </td>

            <td class="cc-col-sync" data-label="Push">
                {{-- Test Failed before Pushed: a row with a VentaCart id would otherwise never show Failed. --}}
                @if($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @elseif($failed)
                    <x-ui.badge tone="danger">Failed</x-ui.badge>
                @elseif($hasVentaId)
                    <x-ui.badge tone="success">Pushed</x-ui.badge>
                @else
                    <span class="x-cell-muted">Not yet</span>
                @endif

                @if(! $isUnlinked)
                    @if($confirmedAt)
                        <span class="cc-checked" title="Last asked {{ $confirmedAt->toDayDateTimeString() }}">Checked {{ $confirmedAt->diffForHumans() }}</span>
                    @elseif($hasVentaId)
                        <span class="cc-checked cc-checked--never">Not checked since the push</span>
                    @endif
                @endif
                @php $ccState = ($listingStates ?? [])[(int) $p->product_id] ?? null; @endphp
                @if($ccState?->driftLabel() && $canManageVenta)
                    @include('partials.catalog-change-button', ['href' => route('ext.ventacart.listings.edit', [$setting->id, (int) $p->product_id, 'compare' => 1, 'back' => request()->getRequestUri()])])
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageVenta)
                @php
                    $gf = ['_return' => $returnTo, 'ids[]' => $p->product_id];
                    $gRow = ($listingStates ?? [])[(int) $p->product_id] ?? null;
                    $menu = ['label' => "Product {$p->product_id} actions", 'store' => $storeName, 'catalog' => route('products.edit', $p->product_id)];
                    if (!$hasVentaId) {
                        $menu['push'] = ['action' => route('ext.ventacart.product-groups.push', [$setting->id, $group->id]), 'label' => 'Send to ' . $storeName, 'fields' => $gf];
                        $menu['link'] = ['action' => route('ext.ventacart.product-groups.link', [$setting->id, $group->id, $p->product_id]), 'fields' => ['_return' => $returnTo]];
                    } else {
                        $menu['update'] = ['action' => route('ext.ventacart.product-groups.push', [$setting->id, $group->id]), 'label' => 'Send to ' . $storeName, 'fields' => $gf];
                        if ($gRow?->state === \App\Integrations\Listings\ListingState::LIVE) {
                            $menu['switch'] = ['action' => route('ext.ventacart.listings.toggle', [$setting->id, $p->product_id]), 'label' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist {$pName} on {$storeName}? Buyers stop seeing it. It can be relisted at any time.", 'verb' => 'Unlist'];
                        } elseif ($gRow?->state === \App\Integrations\Listings\ListingState::INACTIVE) {
                            $menu['switch'] = ['action' => route('ext.ventacart.listings.toggle', [$setting->id, $p->product_id]), 'label' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist {$pName} on {$storeName}? Buyers see it again at once.", 'verb' => 'Relist', 'tone' => 'primary'];
                        }
                        $menu['check'] = ['action' => route('ext.ventacart.product-groups.check', [$setting->id, $group->id]), 'label' => 'Check against ' . $storeName, 'fields' => $gf];
                        $menu['unlink'] = ['action' => route('ext.ventacart.product-groups.unlink', [$setting->id, $group->id, $p->product_id]), 'fields' => ['_return' => $returnTo], 'confirm' => "Unlink {$pName} from {$storeName}? Nothing is deleted there. Pushing it again will re-match or create it."];
                        $menu['delete'] = ['action' => route('ext.ventacart.product-groups.deleteFromVenta', [$setting->id, $group->id, $p->product_id]), 'fields' => ['_return' => $returnTo], 'confirm' => "Delete {$pName} from {$storeName}? It is removed at the storefront and this cannot be undone."];
                    }
                    if ($isManual) {
                        $menu['remove'] = ['action' => route('ext.ventacart.product-groups.removeProduct', [$setting->id, $group->id, $p->product_id]), 'fields' => ['_return' => $returnTo], 'label' => 'Remove from product group', 'verb' => 'Remove', 'tone' => 'primary', 'confirm' => "Take {$pName} out of this product group? Nothing is deleted from the catalog, and nothing on {$storeName} is touched."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageVenta, 'trail' => ['cc-col-chanid', 'cc-col-stat', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

@if($canManageVenta)
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-group-id="{{ $group->id }}"
         data-search-url="{{ route('ext.ventacart.product-groups.searchProducts', $setting->id) }}"
         data-form="ventacart-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the product group's filter does not pick up. Type at least two characters.</p>

    <form id="ventacart-add-products" method="POST" action="{{ route('ext.ventacart.product-groups.addProducts', [$setting->id, $group->id]) }}">
        @csrf
        <input type="hidden" name="_return" value="{{ $returnTo }}">
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalog to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="ventacart-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

<div id="ventacart-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="ventacart-group-progress-title" aria-describedby="ventacart-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="ventacart-group-progress-title" data-progress-title>Talking to VentaCart</div>
                <div class="cc-progress__note" id="ventacart-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>
@endsection
