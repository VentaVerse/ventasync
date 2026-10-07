@extends('layouts.channel')
@section('title', $group->name . ' Products, Lazada')
@section('breadcrumb', $group->name . ' Products')

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product_group') ?? false;
    $canToggleListing = auth()->user()?->hasPermission('manage_lazada/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $returnTo = request()->getRequestUri();

    $q = (string) ($q ?? '');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $hasFilters = $q !== '' || $erpStatus !== 'all' || $syncStatus !== 'all';

    $listUrl = route('ext.lazada.product-groups.products', $group->id);
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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this product group on Lazada
        </p>
    </div>
    <div class="cc-head-actions">
        @if($canManageLazada)
            <form method="POST" action="{{ route('ext.lazada.product-groups.check', $group->id) }}"
                  data-confirm="Check every product in this product group against Lazada? Nothing is sent or changed on Lazada; the ERP updates its own records to match what it finds." data-confirm-tone="primary" data-confirm-verb="Check against Lazada">
                @csrf
                <input type="hidden" name="_return" value="{{ $returnTo ?? request()->getRequestUri() }}">
                <x-ui.button type="submit" variant="secondary">Check against Lazada</x-ui.button>
            </form>
        @endif
        <x-ui.button variant="secondary" :href="route('ext.lazada.product-groups.orphans', $group->id)">Unlinked on Lazada</x-ui.button>
        @if($canManageLazada)
            <x-ui.button :href="route('ext.lazada.product-groups.edit', $group->id)">Edit product group</x-ui.button>
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
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
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="pushed" @selected($syncStatus === 'pushed')>Listed on Lazada</option>
            <option value="pending" @selected($syncStatus === 'pending')>Not listed</option>
            <option value="error" @selected($syncStatus === 'error')>Has an error</option>
            <option value="unlinked" @selected($syncStatus === 'unlinked')>Unlinked</option>
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

@if($canManageLazada)
@php
    $gf = ['_return' => $returnTo];
    $bulk = ['group' => true,
        'push' => ['action' => route('ext.lazada.product-groups.push', $group->id), 'label' => 'Send to Lazada', 'fields' => $gf, 'confirm' => 'Send the selected products to Lazada with this product group\'s settings? Anything already on Lazada is updated there.'],
        'link' => ['action' => route('ext.lazada.product-groups.check', $group->id), 'label' => 'Link IDs', 'fields' => $gf],
        'switch' => [
            ['action' => route('ext.lazada.products.bulk_toggle'), 'field' => 'product_ids[]', 'verb' => 'deactivate', 'codes' => 'active', 'label' => 'Deactivate :n on Lazada', 'button' => 'Deactivate on Lazada', 'fields' => ['action' => 'deactivate'], 'confirm' => 'Deactivate the selected items on Lazada? Buyers stop seeing them. They can be activated again at any time.'],
            ['action' => route('ext.lazada.products.bulk_toggle'), 'field' => 'product_ids[]', 'verb' => 'activate', 'codes' => 'inactive', 'label' => 'Activate :n on Lazada', 'button' => 'Activate on Lazada', 'fields' => ['action' => 'activate'], 'confirm' => 'Activate the selected items on Lazada? Buyers see them again once Lazada applies it.'],
        ],
        'delete' => ['action' => route('ext.lazada.product-groups.deleteFromLazada', $group->id), 'label' => 'Delete from Lazada', 'fields' => $gf, 'confirm' => 'Delete the selected products from Lazada? They are removed at the marketplace and this cannot be undone.'],
        'remove' => ['action' => route('ext.lazada.product-groups.massRemove', $group->id), 'label' => 'Remove from product group', 'fields' => $gf, 'confirm' => 'Take the selected products out of this product group? Nothing is deleted from the catalog, and nothing already live on Lazada is touched.'],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this product group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalog and push filters.'
                    : 'Nothing is in this product group yet. Add products from this store\'s list and they take the group\'s category, brand and markup on the next push.'">
        @if($canManageLazada && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
@php $colCount = 8 + ($canManageLazada ? 1 : 0); @endphp
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageLazada)
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
            <th scope="col" class="cc-col-chanid">Lazada ID</th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pivot = $pivotMap->get($p->product_id);

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? \App\Services\Media\ImageCache::url($img) : null;

            $listed = !empty($p->lazada_item_id) && empty($p->lazada_deleted_at);

            $syncState = $pivot->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked' && ! $listed;
            $attemptFailed = $syncState === 'error';
            $attemptError = $attemptFailed ? ($pivot->push_error ?? null) : null;
            $attemptAt = ($pivot->last_pushed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($pivot->last_pushed_at)
                : null;

            $provenanceAt = ($p->listing_last_pushed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($p->listing_last_pushed_at)
                : null;
            $provenanceSource = (string) ($p->last_push_source ?? '');
            $provenanceLabel = match (true) {
                $provenanceSource === 'listing' => 'from its listing',
                str_starts_with($provenanceSource, 'group:') => 'from the ' . substr($provenanceSource, 6) . ' product group',
                default => '',
            };

            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $rowState = ($listingStates ?? [])[(int) $p->product_id] ?? null;
            $stateClass = \App\Integrations\Listings\ListingState::class;

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
        @include('partials.channel-error-row', ['error' => ($rowErrors ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount, 'productId' => (int) $p->product_id])
        <tr>
            @if($canManageLazada)
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

            <td class="cc-col-chanid" data-label="Lazada ID">
                @if($listed)
                    <span class="x-num">{{ $p->lazada_item_id }}</span>
                @else
                    <span class="x-cell-muted">Not listed</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalog">
                <x-ui.badge :tone="(int) $p->status === 1 ? 'success' : 'neutral'">{{ (int) $p->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>

            <td class="cc-col-sync" data-label="Listing">
                @if($listed && $rowState && $rowState->state !== $stateClass::UNKNOWN && $rowState->state !== $stateClass::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                @elseif($listed)
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @else
                    <span class="x-cell-muted">Not listed</span>
                @endif
                @php $ccState = ($listingStates ?? [])[(int) $p->product_id] ?? null; @endphp
                @if($ccState?->driftLabel() && $canManageLazada)
                    @include('partials.catalog-change-button', ['href' => route('ext.lazada.products.edit', ['productId' => (int) $p->product_id, 'compare' => 1, 'back' => request()->getRequestUri()])])
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                @php
                    $gf = ['_return' => $returnTo, 'ids[]' => $p->product_id];
                    $lzId = ($lazadaIdByProductId ?? collect())->get($p->product_id);
                    $menu = ['label' => "Product {$p->product_id} actions", 'store' => 'Lazada', 'catalog' => $canEditCatalog ? route('products.edit', $p->product_id) : null];
                    if (!$listed && !$isUnlinked) {
                        $menu['push'] = ['action' => route('ext.lazada.product-groups.push', $group->id), 'label' => 'Send to Lazada', 'fields' => $gf];
                        $menu['link'] = ['action' => route('ext.lazada.product-groups.syncId', [$group->id, $p->product_id]), 'fields' => ['_return' => $returnTo]];
                    } elseif ($isUnlinked) {
                        $menu['link'] = ['action' => route('ext.lazada.product-groups.linkProduct', [$group->id, $p->product_id]), 'fields' => ['_return' => $returnTo]];
                    }
                    if ($listed) {
                        $menu['update'] = ['action' => route('ext.lazada.product-groups.updateProduct', $group->id), 'label' => 'Send to Lazada', 'fields' => $gf];
                        if ($canToggleListing && $lzId && $rowState?->state === $stateClass::LIVE) {
                            $menu['switch'] = ['action' => route('ext.lazada.listings.toggle', $p->product_id), 'label' => 'Deactivate on Lazada', 'fields' => ['action' => 'deactivate'], 'confirm' => "Deactivate {$pName} on Lazada? Buyers stop seeing it. It can be activated again at any time.", 'verb' => 'Deactivate'];
                        } elseif ($canToggleListing && $lzId && $rowState?->state === $stateClass::INACTIVE) {
                            $menu['switch'] = ['action' => route('ext.lazada.listings.toggle', $p->product_id), 'label' => 'Activate on Lazada', 'fields' => ['action' => 'activate'], 'confirm' => "Activate {$pName} on Lazada? Buyers see it again once Lazada applies it.", 'verb' => 'Activate', 'tone' => 'primary'];
                        }
                        $menu['check'] = ['action' => route('ext.lazada.product-groups.check', $group->id), 'label' => 'Check against Lazada', 'fields' => $gf];
                        $menu['unlink'] = ['action' => route('ext.lazada.product-groups.unlinkProduct', [$group->id, $p->product_id]), 'fields' => ['_return' => $returnTo], 'confirm' => "Unlink {$pName} from Lazada? The product stays in the product group but sync will stop."];
                        $menu['delete'] = ['action' => route('ext.lazada.product-groups.deleteFromLazada', $group->id), 'fields' => $gf, 'confirm' => "Delete {$pName} from Lazada? It is removed at the marketplace and this cannot be undone."];
                    }
                    if ($isManual) {
                        $menu['remove'] = ['action' => route('ext.lazada.product-groups.removeProduct', [$group->id, $p->product_id]), 'method' => 'DELETE', 'fields' => ['_return' => $returnTo], 'label' => 'Remove from product group', 'verb' => 'Remove', 'tone' => 'primary', 'confirm' => "Take {$pName} out of the product group? Nothing is deleted from the catalog, and nothing already live on Lazada is touched."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageLazada, 'trail' => ['cc-col-chanid', 'cc-col-stat', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

<div id="lazada-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="lazada-group-progress-title" aria-describedby="lazada-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="lazada-group-progress-title" data-progress-title>Talking to Lazada</div>
                <div class="cc-progress__note" id="lazada-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManageLazada)
    <x-channel.add-panel
        id="lazada-group-add-panel"
        :search-url="route('ext.lazada.product-groups.productSearch', $group->id)"
        :add-url="route('ext.lazada.product-groups.addProduct', ['id' => $group->id, 'productId' => '__PID__'])"
        :sub="'Search your Master Catalog. Each product you add joins ' . $group->name . ' and takes its category, brand and markup on the next push; one not on this store yet is listed on it as it joins. A product belongs to one group: adding one that sits in another group moves it here.'"
        search-label="Search your Master Catalog"
        toggle-label="Show products already in this group"
        in-label="In this group"
        scope="this group"
        empty-default="Every product in your catalog is already in this group."
        empty-all="Your catalog has no enabled products yet."
        list-label="Products" />
@endif

</div>
@endsection
