@extends('layouts.channel')
@section('title', $group->name . ' Products, Shopee')
@section('breadcrumb', $group->name . ' Products')

@section('content')
@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;
    $canToggleListing = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $returnTo = request()->getRequestUri();

    $q = (string) ($q ?? '');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $hasFilters = $q !== '' || $erpStatus !== 'all' || $syncStatus !== 'all';

    $listUrl = route('ext.shopee.product-groups.products', $group->id);
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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this product group on Shopee
        </p>
    </div>
    @if($canManageShopee)
        <div class="cc-head-actions">
            @if($canManageShopee)
                <form method="POST" action="{{ route('ext.shopee.product-groups.check', $group->id) }}"
                      data-confirm="Check every product in this product group against Shopee? Nothing is sent or changed on Shopee; the ERP updates its own records to match what it finds." data-confirm-tone="primary" data-confirm-verb="Check against Shopee">
                    @csrf
                    <input type="hidden" name="_return" value="{{ $returnTo }}">
                    <x-ui.button type="submit" variant="secondary">Check against Shopee</x-ui.button>
                </form>
            @endif
            <x-ui.button variant="secondary" :href="route('ext.shopee.product-groups.orphans', $group->id)">Unlinked on Shopee</x-ui.button>
            <x-ui.button :href="route('ext.shopee.product-groups.edit', $group->id)">Edit product group</x-ui.button>
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

@include('ext-shopee::products._scope_strips', [
    'stripRoute' => 'ext.shopee.product-groups.products',
    'stripParams' => ['id' => $group->id],
])

<form method="GET" action="{{ $listUrl }}" class="x-filters">
    <input type="hidden" name="shopee_tab" value="{{ $shopeeTab ?? 'all' }}">
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
            <option value="pushed" @selected($syncStatus === 'pushed')>Listed on Shopee</option>
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

@if(($failedCount ?? 0) > 0 && $syncStatus !== 'error')
    <p class="cc-failnote">
        <x-ui.icon name="alert-triangle" size="13" class="cc-failnote__icon" />
        <span><span class="x-num">{{ number_format($failedCount) }}</span>
            {{ \Illuminate\Support\Str::plural('product', $failedCount) }} in this product group
            {{ $failedCount === 1 ? 'has' : 'have' }} an error.</span>
        <a class="cc-failnote__link" href="{{ request()->fullUrlWithQuery(['sync_status' => 'error', 'page' => null]) }}">Show them</a>
    </p>
@endif

@if($canManageShopee)
@php
    $gf = ['_return' => $returnTo];
    $bulk = ['group' => true,
        'push' => ['action' => route('ext.shopee.product-groups.send', $group->id), 'label' => 'Send to Shopee', 'fields' => $gf, 'confirm' => 'Send the selected products to Shopee? Each one is checked against Shopee first, then created or updated to match the catalog, with this product group\'s settings.'],
        'link' => ['action' => route('ext.shopee.product-groups.check', $group->id), 'label' => 'Link IDs', 'fields' => $gf],
        'switch' => [
            ['action' => route('ext.shopee.products.bulk_toggle'), 'field' => 'product_ids[]', 'verb' => 'unlist', 'codes' => 'NORMAL', 'label' => 'Delist :n from Shopee', 'button' => 'Delist from Shopee', 'fields' => ['action' => 'unlist'], 'confirm' => 'Delist the selected items from Shopee? Buyers stop seeing them immediately. They can be published again at any time.'],
            ['action' => route('ext.shopee.products.bulk_toggle'), 'field' => 'product_ids[]', 'verb' => 'list', 'codes' => 'UNLIST', 'label' => 'Publish :n on Shopee', 'button' => 'Publish on Shopee', 'fields' => ['action' => 'list'], 'confirm' => 'Publish the selected items on Shopee? Buyers see them again immediately.'],
        ],
        'delete' => ['action' => route('ext.shopee.product-groups.deleteFromShopee', $group->id), 'label' => 'Delete from Shopee', 'fields' => $gf, 'confirm' => 'Delete the selected products from Shopee? They are removed at the marketplace and this cannot be undone.'],
        'remove' => ['action' => route('ext.shopee.product-groups.massRemove', $group->id), 'label' => 'Remove from product group', 'fields' => $gf, 'confirm' => 'Take the selected products out of this product group? Nothing is deleted from the catalog, and nothing already live on Shopee is touched.'],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this product group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalog and push filters.'
                    : 'Nothing is in this product group yet. Add products from this store\'s list and they take the group\'s category, couriers and markup on the next push.'">
        @if($canManageShopee && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
@php $colCount = 8 + ($canManageShopee ? 1 : 0); @endphp
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageShopee)
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
            <th scope="col" class="cc-col-chanid">Shopee ID</th>
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

            $rowLink = ($linksByProductId->get((int) $p->product_id) ?? collect())->first();
            $rowListing = $listingsByProductId->get((int) $p->product_id);
            $listed = $rowLink !== null;

            $syncState = $pivot->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked' && ! $listed;
            $sharedError = trim((string) ($rowListing?->last_push_error ?? ''));
            $attemptFailed = $sharedError !== '' || $syncState === 'error';
            $attemptError = $sharedError !== '' ? $sharedError : ($attemptFailed ? ($pivot->push_error ?? null) : null);
            $attemptAt = $sharedError !== '' && ($rowListing?->last_push_failed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($rowListing->last_push_failed_at)
                : (($pivot->last_pushed_at ?? null) ? \Illuminate\Support\Carbon::parse($pivot->last_pushed_at) : null);

            $provenanceAt = $rowListing?->last_pushed_at;
            $provenanceSource = (string) ($rowListing->last_push_source ?? '');
            $provenanceLabel = match (true) {
                $provenanceSource === 'form' => 'from the push form',
                $provenanceSource === 'listing' => 'from its listing',
                str_starts_with($provenanceSource, 'group:') => 'from the ' . substr($provenanceSource, 6) . ' product group',
                default => '',
            };

            $confirmedAt = $rowListing?->last_checked_at;

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
            @if($canManageShopee)
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
                <x-money :php="(float) ($rowPrices[(int) $p->product_id] ?? $p->price)" />
            </td>

            <td class="cc-col-chanid" data-label="Shopee ID">
                @if($listed)
                    @php $liveUrl = \Extensions\shopee\Models\ShopeeSetting::publicListingUrl($rowLink->shopee_item_id); @endphp
                    @if($liveUrl)
                        <a class="x-num" href="{{ $liveUrl }}" target="_blank" rel="noopener">{{ $rowLink->shopee_item_id }}<x-ui.icon name="external-link" size="10" class="x-extlink" /><span class="x-sr"> (opens Shopee in a new tab)</span></a>
                    @else
                        <span class="x-num">{{ $rowLink->shopee_item_id }}</span>
                    @endif
                @else
                    <span class="x-cell-muted">Not listed</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalog">
                <x-ui.badge :tone="(int) $p->status === 1 ? 'success' : 'neutral'">{{ (int) $p->status === 1 ? 'Enabled' : 'Disabled' }}</x-ui.badge>
            </td>

            <td class="cc-col-sync" data-label="Listing">
                @if($listed)
                    @php $rowState = ($listingStates ?? [])[(int) $p->product_id] ?? null; @endphp
                    @if($rowState && $rowState->state !== \App\Integrations\Listings\ListingState::UNKNOWN)
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="success">Listed</x-ui.badge>
                    @endif
                @elseif($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @else
                    <span class="x-cell-muted">Not listed</span>
                @endif
                @php $ccState = ($listingStates ?? [])[(int) $p->product_id] ?? null; @endphp
                @if($ccState?->driftLabel() && $canManageShopee)
                    @include('partials.catalog-change-button', ['href' => route('ext.shopee.listings.edit', [$p->product_id, 'compare' => 1, 'back' => request()->getRequestUri()])])
                @endif

                @if($listed)
                    @if($confirmedAt)
                        <span class="cc-checked" title="Last asked {{ $confirmedAt->toDayDateTimeString() }}">Checked {{ $confirmedAt->diffForHumans() }}</span>
                    @else
                        <span class="cc-checked cc-checked--never">Not checked since the push</span>
                    @endif
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageShopee)
                @php
                    $gf = ['_return' => $returnTo, 'ids[]' => $p->product_id];
                    $menu = ['label' => "Product {$p->product_id} actions", 'store' => 'Shopee', 'catalog' => $canEditCatalog ? route('products.edit', $p->product_id) : null];
                    if (!$listed) {
                        $menu['push'] = ['action' => route('ext.shopee.product-groups.send', $group->id), 'label' => 'Send to Shopee', 'fields' => $gf];
                        $menu['link'] = ['action' => route('ext.shopee.products.sync_shopee_id', $p->product_id)];
                    } else {
                        $menu['update'] = ['action' => route('ext.shopee.product-groups.send', $group->id), 'label' => 'Send to Shopee', 'fields' => $gf];
                        if ($canToggleListing && $rowState?->state === \App\Integrations\Listings\ListingState::LIVE) {
                            $menu['switch'] = ['action' => route('ext.shopee.listings.toggle', $p->product_id), 'label' => 'Delist from Shopee', 'fields' => ['action' => 'unlist'], 'confirm' => "Delist {$pName} from Shopee? Buyers stop seeing it immediately. It can be published again at any time.", 'verb' => 'Delist'];
                        } elseif ($canToggleListing && $rowState?->state === \App\Integrations\Listings\ListingState::INACTIVE) {
                            $menu['switch'] = ['action' => route('ext.shopee.listings.toggle', $p->product_id), 'label' => 'Publish on Shopee', 'fields' => ['action' => 'list'], 'confirm' => "Publish {$pName} on Shopee? Buyers see it again immediately.", 'verb' => 'Publish', 'tone' => 'primary'];
                        }
                        $menu['check'] = ['action' => route('ext.shopee.product-groups.check', $group->id), 'label' => 'Check against Shopee', 'fields' => $gf];
                        $menu['unlink'] = ['action' => route('ext.shopee.product-groups.unlinkProduct', [$group->id, $p->product_id]), 'fields' => ['_return' => $returnTo], 'confirm' => "Unlink {$pName} from Shopee? The listing stays on Shopee; the ERP just stops tracking it. Send will re-find it."];
                        $menu['delete'] = ['action' => route('ext.shopee.product-groups.deleteFromShopee', $group->id), 'fields' => $gf, 'confirm' => "Delete {$pName} from Shopee? It is removed at the marketplace and this cannot be undone."];
                    }
                    if ($isManual) {
                        $menu['remove'] = ['action' => route('ext.shopee.product-groups.removeProduct', [$group->id, $p->product_id]), 'method' => 'DELETE', 'fields' => ['_return' => $returnTo], 'label' => 'Remove from product group', 'verb' => 'Remove', 'tone' => 'primary', 'confirm' => "Take {$pName} out of the product group? Nothing is deleted from the catalog, and nothing already live on Shopee is touched."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($variationCount > 0)
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManageShopee, 'trail' => ['cc-col-chanid', 'cc-col-stat', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

<div id="shopee-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="shopee-group-progress-title" aria-describedby="shopee-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="shopee-group-progress-title" data-progress-title>Talking to Shopee</div>
                <div class="cc-progress__note" id="shopee-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManageShopee)
    <x-channel.add-panel
        id="shopee-group-add-panel"
        :search-url="route('ext.shopee.product-groups.productSearch', $group->id)"
        :add-url="route('ext.shopee.product-groups.addProduct', ['id' => $group->id, 'productId' => '__PID__'])"
        :sub="'Search your Master Catalog. Each product you add joins ' . $group->name . ' and takes its category, couriers and markup on the next push; one not on this store yet is listed on it as it joins. A product belongs to one group: adding one that sits in another group moves it here.'"
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
