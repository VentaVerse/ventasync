@extends('layouts.channel')
@section('breadcrumb', 'Listings')
@section('title', $setting->store_name . ' Listings')

{{-- Venta listings (#171), on the same cc- anatomy as Shopee's, Lazada's
     and TikTok's: one row per catalogue product in scope on this store,
     the store's own status from the mirror on the listing row (refreshed
     every 30 minutes, on demand, and by every push, unlist and relist),
     and the row's verbs in its menu. --}}
@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_venta/listing') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;
    $storeName = $setting->store_name;

    $hasFilters = $q !== '' || $groupFilter !== 'all' || $erpStatus !== 'all' || $syncStatus !== 'all'
        || $ventaTab !== 'all' || ($failedFlag ?? false) || ($changeFlag ?? false);
    $toggleDir = fn (string $col) => $sort !== $col ? 'asc' : ($dir === 'asc' ? 'desc' : 'asc');
    $sortUrl = function (string $col) use ($toggleDir) {
        $qp = request()->query();
        $qp['sort'] = $col;
        $qp['dir'] = $toggleDir($col);
        unset($qp['page']);
        return url()->current() . '?' . http_build_query($qp);
    };
    $statusMap = \Extensions\venta\Services\Venta\VentaListingMirror::STATUS_MAP;
    $tabDefs = \Extensions\venta\Services\Venta\VentaListingMirror::TABS;
    $tabQuery = request()->except('page', 'venta_tab');
    $indexUrl = fn (array $params = []) => route('ext.venta.listings.index', array_merge(['store' => $setting->id], $params));
@endphp

<div class="cc-page" id="venta-listings-page" data-store-name="{{ $storeName }}"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($products->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}@if($statusMenu['label'] ?? null): {{ $statusMenu['label'] }}@endif
            @else
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in scope for {{ $storeName }}
            @endif
        </p>
    </div>
    @if($canManage)
        <div class="cc-head-actions">
            {{-- Add products: the one way a main-catalogue product joins this
                 store, opening the slide-over in place. Refresh sits in the
                 status strip below. --}}
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

@include('partials.channel-state-filter', [
    'bucket' => $troubleFilter ?? null,
    'count' => $products->total(),
    'clear' => route('ext.venta.listings.index', array_merge(['store' => $setting->id], request()->except(['state', 'page']))),
])

{{-- A store that cannot be asked says so, once, at the top. The buckets
     below are the mirror's last word, not a live read, so they stay; what
     would otherwise be missing is any reason for every row reading "not
     checked yet". --}}
@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => $storeName, 'raw' => $liveTabError, 'settingsRoute' => null])</div>
    </div>
@endif

{{-- The store's own statuses, as the tab row above the table: the
     catalogue's total and the store's buckets from the mirror, beside the
     menu's VentaSync status. The two combine, and each one's counts follow
     the other's choice. The strip says when the mirror was refreshed. --}}
<div class="x-segment-bar">
    <div class="x-segment-groups">
        <div class="x-segment-group">
            <span class="x-segment-group__k">Your catalogue</span>
            <nav class="x-segment" aria-label="Catalogue">
                <a href="{{ $indexUrl($tabQuery) }}"
                   class="x-segment__item {{ $ventaTab === 'all' ? 'is-active' : '' }}"
                   @if($ventaTab === 'all') aria-current="true" @endif>
                    <span>All products</span>
                    <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                </a>
            </nav>
        </div>
        <div class="x-segment-group">
            <span class="x-segment-group__k">On {{ $storeName }}</span>
            <nav class="x-segment" aria-label="{{ $storeName }} listing status">
                @foreach($tabDefs as $tabKey => [$tabLabel, $tabStatus])
                    @continue($tabKey === 'all')
                    @php $isActive = $ventaTab === $tabKey; @endphp
                    <a href="{{ $indexUrl(array_merge($tabQuery, ['venta_tab' => $tabKey])) }}"
                       class="x-segment__item {{ $isActive ? 'is-active' : '' }}" @if($isActive) aria-current="true" @endif>
                        <span>{{ $tabLabel }}</span>
                        <span class="x-segment__count">{{ number_format((int) ($liveCounts[$tabKey] ?? 0)) }}</span>
                    </a>
                @endforeach
            </nav>
            <span class="x-segment-group__meta">
                @if($liveCheckedAt ?? null)
                    as of {{ $liveCheckedAt->format('H:i, M j') }}
                @else
                    not refreshed yet
                @endif
            </span>
            @if($canManage && $setting->enabled)
                {{-- Refresh checks every link against the store and re-reads every status. --}}
                <form method="POST" action="{{ route('ext.venta.listings.refresh_status', $setting->id) }}" class="x-segment-group__refresh" data-slow-action>
                    @csrf
                    <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from {{ $storeName }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="lsm-layout">
@include('partials.listing-status-menu', ['menu' => $statusMenu])
<div class="lsm-layout__main">

@php
    /* The group a Listings page stands in, and what acts on it: its settings
       page, and a Send that uses those settings for the ticked rows. */
    $canManageGroups = auth()->user()?->hasPermission('manage_venta/product_group') ?? false;
    $chosenGroup = collect($statusMenu['groups'])->first(fn ($g) => $g['active'] && $g['key'] !== 'none');
    $groupLine = ($chosenGroup && $canManageGroups) ? [
        'return' => request()->getRequestUri(),
        'manage' => route('ext.venta.product-groups.edit', [$setting->id, $chosenGroup['key']]),
        'send' => [
            'action' => route('ext.venta.product-groups.push', [$setting->id, $chosenGroup['key']]),
            'confirm' => 'Send the ticked products to ' . $storeName . ' on the settings of ' . $chosenGroup['label'] . ', rather than their own?',
        ],
    ] : null;
@endphp
@include('partials.listing-stand-segment', ['menu' => $statusMenu, 'group' => $groupLine])

<form method="GET" action="{{ $indexUrl() }}" class="x-filters">
    {{-- The menu's choice rides a search. --}}
    @if($ventaTab !== 'all')
        <input type="hidden" name="venta_tab" value="{{ $ventaTab }}">
    @endif
    @if($syncStatus !== 'all')
        <input type="hidden" name="sync_status" value="{{ $syncStatus }}">
    @endif
    {{-- The rail's choice rides a search too, or searching would drop it. --}}
    @if(($groupFilter ?? 'all') !== 'all')
        <input type="hidden" name="group" value="{{ $groupFilter }}">
    @endif
    @if($failedFlag ?? false)
        <input type="hidden" name="failed" value="1">
    @endif
    @if($changeFlag ?? false)
        <input type="hidden" name="change" value="1">
    @endif
    <x-ui.input type="search" name="q" class="x-filters__search" value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search listings" />
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalogue status">
            <option value="all" @selected($erpStatus === 'all')>Any catalogue status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $indexUrl() }}">Clear</a>
    @endif
</form>

@if($canManage && $setting->enabled)
@php
    $bulk = [
        /* Filing the ticked rows: into a group, between groups, or out of
           every group; and, standing inside one, the quick way out of it. */
        'move' => $canManageGroups ? [
            'action' => route('ext.venta.product-groups.move', $setting->id),
            'groups' => collect($statusMenu['groups'])->reject(fn ($g) => $g['key'] === 'none')
                ->map(fn ($g) => ['id' => $g['key'], 'name' => $g['label'], 'count' => $g['count']])->values()->all(),
            'current' => $groupFilter ?? 'all',
            'return' => request()->getRequestUri(),
        ] : null,
        'ungroup' => ($canManageGroups && $chosenGroup) ? [
            'action' => route('ext.venta.product-groups.mass-remove', [$setting->id, $chosenGroup['key']]),
            'label' => 'Remove from group',
            'confirm' => 'Take these products out of ' . $chosenGroup['label'] . '? They become ungrouped and stop taking its settings on the next push.',
            'return' => request()->getRequestUri(),
        ] : null,
        /* Multi-tick push (2026-09-09): each ticked product goes up on
           its own settings and reports for itself. */
        'push' => ['action' => route('ext.venta.listings.bulk_push', $setting->id), 'label' => 'Send to ' . $storeName,
            'confirm' => "Send the selected products to {$storeName}? Each goes up on its own settings, and anything not ready is named rather than sent."],
        'switch' => [
            ['action' => route('ext.venta.listings.bulk_toggle', $setting->id), 'verb' => 'unlist', 'codes' => 'active', 'label' => 'Unlist :n on ' . $storeName, 'button' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist the selected products on {$storeName}? Buyers stop seeing them. They can be relisted at any time."],
            ['action' => route('ext.venta.listings.bulk_toggle', $setting->id), 'verb' => 'relist', 'codes' => 'inactive', 'label' => 'Relist :n on ' . $storeName, 'button' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist the selected products on {$storeName}? Buyers see them again at once."],
        ],
        'link' => ['action' => route('ext.venta.listings.check', $setting->id), 'label' => 'Link IDs'],
        'delete' => ['action' => route('ext.venta.products.bulk_delete', $setting->id), 'label' => 'Delete from ' . $storeName, 'confirm' => "Delete the selected products from {$storeName}? They are removed at the storefront and this cannot be undone."],
        'remove' => ['action' => route('ext.venta.products.bulk_remove_from_store', $setting->id), 'label' => 'Remove from this channel', 'confirm' => "Remove the selected products from this channel? They leave this list and their groups and stop syncing. Anything already on {$storeName} stays up until you delete it there."],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'Nothing on this store yet' }}">
        <x-slot:action>
            @if($hasFilters)
                <x-ui.button :href="$indexUrl()">Clear filters</x-ui.button>
            @elseif($canManage)
                <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
            @endif
        </x-slot:action>
    </x-ui.empty>
@else
@php $colCount = 6 + ($canManage ? 1 : 0); @endphp
<x-ui.table id="venta-listings-table">
    <x-slot:head>
        <tr>
            @if($canManage)
            <th scope="col" class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th scope="col" class="cc-col-id"><a class="x-th-sort" href="{{ $sortUrl('id') }}">ID @if($sort === 'id')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col"><a class="x-th-sort" href="{{ $sortUrl('product') }}">Product @if($sort === 'product')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-qty x-td-num"><a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('quantity') }}">Stock @if($sort === 'quantity')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-price x-td-num"><a class="x-th-sort x-th-sort--num" href="{{ $sortUrl('price') }}">Price @if($sort === 'price')<x-ui.icon name="{{ $dir === 'asc' ? 'chevron-up' : 'chevron-down' }}" size="12" />@endif</a></th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pid = (int) $p->product_id;
            $link = $linksByProductId->get($pid);
            $listing = $listingsByProductId->get($pid);
            $listed = (bool) ($link?->venta_product_id);
            $attempt = $lastAttempts->get($pid);
            $pGroups = $groupsByProductId->get($pid, []);
            $optRows = $optionRowsByProductId->get($pid);
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
            /* The mirrored status for this row; the strip says when it was
               refreshed. Never refreshed reads as exactly that, not as a guess. */
            $rowLiveStatus = $listed ? ($listing?->live_status) : null;
            $attemptFailed = $attempt && in_array($attempt->sync_status, ['error', 'failed'], true);
            $listingUrl = route('ext.venta.listings.edit', [$setting->id, $pid, 'back' => request()->getRequestUri()]);
        @endphp
        @include('partials.channel-error-row', ['error' => $rowErrors[$pid] ?? null, 'cols' => $colCount])
        @include('partials.channel-ready-row', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null, 'cols' => $colCount, 'productId' => (int) $p->product_id])
        <tr>
            @if($canManage)
            <td class="cc-col-check">
                <input type="checkbox" class="cc-check" data-row-check value="{{ (string) $pid }}" x-model="selected" aria-label="Select product {{ $pid }}"
                       data-live="{{ $listed ? ($rowLiveStatus ?? '') : 'NOT_LISTED' }}">
            </td>
            @endif
            <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $pid }}</span></td>
            <td data-label="Product">
                <div class="co-item">
                    <div class="cc-thumb">
                        <span class="co-item__none">No image</span>
                        @if($thumbSrc)<img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>@endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ $listingUrl }}">{{ ($rowTitles[(int) $p->product_id] ?? $p->name) ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: ($p->model ?: 'No SKU') }}</span>
                            @if($listed)
                                <span class="co-item__sku lsm-chanid">Venta {{ $link->venta_product_id }}</span>
                            @endif
                            @if($p->manufacturer_name)<span>{{ $p->manufacturer_name }}</span>@endif
                            @if(($groupFilter ?? 'all') === 'all' && count($pGroups) > 0)
                                <a class="co-item__var" href="{{ $indexUrl(array_merge(request()->except(['page', 'sync_status', 'group']), ['group' => $pGroups[0]['id']])) }}">Product group: {{ $pGroups[0]['name'] }}</a>
                            @endif
                        </div>
                    </div>
                </div>
            </td>
            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cc-col-sync" data-label="Listing">
                {{-- The engine's one state (partials.channel-listing-state), the
                     cell every other channel draws, since 2026-09-06. --}}
                @php $state = $listingStates[$pid] ?? null; @endphp
                @if($state)
                    @include('partials.channel-listing-state', ['state' => $state, 'listingUrl' => $listingUrl, 'canCompare' => $canManage])
                @elseif($listed)
                    <x-ui.badge tone="neutral">Not checked yet</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManage || $canEditCatalog)
                @php
                    $menu = [
                        'label' => "Product {$pid} actions",
                        'store' => $storeName,
                        'edit' => $listingUrl,
                        'catalog' => $canEditCatalog ? route('products.edit', $pid) : null,
                    ];
                    if ($canManage && $setting->enabled) {
                        if (!$listed) {
                            $menu['push'] = ['href' => $listingUrl, 'label' => 'Send to ' . $storeName];
                            $menu['link'] = ['action' => route('ext.venta.listings.check', $setting->id), 'fields' => ['product_ids[]' => $pid]];
                        } else {
                            $menu['update'] = ['action' => route('ext.venta.listings.push', [$setting->id, $pid]), 'label' => 'Send to ' . $storeName, 'confirm' => "Send this listing's content and price rule to the live {$storeName} product? Name, description, price, stock, images and variations are updated in place.", 'verb' => 'Send', 'tone' => 'primary'];
                            if ($rowLiveStatus === \Extensions\venta\Models\VentaListing::STATUS_ACTIVE) {
                                $menu['switch'] = ['action' => route('ext.venta.listings.toggle', [$setting->id, $pid]), 'label' => 'Unlist on ' . $storeName, 'fields' => ['action' => 'unlist'], 'confirm' => "Unlist this product on {$storeName}? Buyers stop seeing it. It can be relisted at any time.", 'verb' => 'Unlist'];
                            } elseif ($rowLiveStatus === \Extensions\venta\Models\VentaListing::STATUS_INACTIVE) {
                                $menu['switch'] = ['action' => route('ext.venta.listings.toggle', [$setting->id, $pid]), 'label' => 'Relist on ' . $storeName, 'fields' => ['action' => 'relist'], 'confirm' => "Relist this product on {$storeName}? Buyers see it again at once.", 'verb' => 'Relist', 'tone' => 'primary'];
                            }
                            $menu['check'] = ['action' => route('ext.venta.listings.check', $setting->id), 'label' => 'Check against ' . $storeName, 'fields' => ['product_ids[]' => $pid]];
                            $menu['unlink'] = ['action' => route('ext.venta.products.unlink', [$setting->id, $pid]), 'confirm' => "Unlink this product from {$storeName}? Only the link is removed. The store product stays up and can be linked again later."];
                            $menu['delete'] = ['action' => route('ext.venta.products.delete', [$setting->id, $pid]), 'confirm' => "Delete this product from {$storeName}? It is removed at the storefront and this cannot be undone."];
                        }
                    }
                    if ($canManage) {
                        $menu['remove'] = ['action' => route('ext.venta.products.remove_from_store', [$setting->id, $pid]), 'label' => 'Remove from this channel', 'verb' => 'Remove', 'confirm' => "Remove this product from this channel? It leaves this list and its group and stops syncing. Anything already on {$storeName} stays up until you delete it there."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($optRows && count($optRows) > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManage, 'trail' => ['cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

</div>{{-- /.lsm-layout__main --}}
</div>{{-- /.lsm-layout --}}

<div id="venta-listings-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="venta-listings-progress-title" aria-describedby="venta-listings-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="venta-listings-progress-title" data-progress-title>Talking to {{ $storeName }}</div>
                <div class="cc-progress__note" id="venta-listings-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

@if($canManage)
    <x-channel.add-panel
        id="venta-add-panel"
        :search-url="route('ext.venta.products.catalogue_search', $setting->id)"
        :add-url="route('ext.venta.products.add_to_store', [$setting->id, '__PID__'])"
        sub="Search your main catalog. Each product you add joins this store's list, ready to configure and push to {{ $storeName }}."
        toggle-label="Show products already on this store"
        in-label="On this store"
        scope="this store"
        empty-default="Every enabled product is already on this store."
        empty-all="Your catalog has no enabled products."
        list-label="Catalog products" />
@endif
</div>
@endsection
