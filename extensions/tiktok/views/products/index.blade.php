@extends('layouts.channel')
@section('breadcrumb', 'Listings')
@section('title', 'TikTok Shop Listings')

{{-- TikTok listings (#156), on the same cc- anatomy as Shopee's and
     Lazada's: one row per catalog product in scope, the marketplace's own
     status from the mirror on the listing row (refreshed every 30
     minutes, on demand, and by every activate and deactivate), readiness
     before a push, and the row's verbs in its menu. --}}
@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    $hasFilters = $q !== '' || $syncStatus !== 'all' || $manufacturerFilter !== 'all' || $groupFilter !== 'all' || $erpStatus !== 'all';
    $toggleDir = fn (string $col) => $sort !== $col ? 'asc' : ($dir === 'asc' ? 'desc' : 'asc');
    $sortUrl = function (string $col) use ($toggleDir) {
        $qp = request()->query();
        $qp['sort'] = $col;
        $qp['dir'] = $toggleDir($col);
        unset($qp['page']);
        return url()->current() . '?' . http_build_query($qp);
    };
    $tabDefs = \Extensions\tiktok\Services\TikTok\TikTokLiveListing::TABS;
    $tabQuery = request()->except('page', 'tiktok_tab');
@endphp

<div class="cc-page"
     x-data="{
        selected: [],
        ids: {{ \Illuminate\Support\Js::from($products->pluck('product_id')->map(fn ($v) => (string) $v)->values()) }}
     }">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Listings</h1>
        <p class="x-page-sub">
            {{-- This store's own list now (#201): the products this store
                 carries, not the whole catalogue. A filtered count says what
                 it is counting. --}}
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($catalogueTotal ?? $products->total()) }} {{ ($catalogueTotal ?? $products->total()) === 1 ? 'product' : 'products' }} on this store
                &middot; {{ number_format($listedTotal ?? 0) }} on TikTok Shop
            @endif
        </p>
    </div>
    @if($canManage)
        <div class="cc-head-actions">
            {{-- Refresh and Repair links live beside the freshness stamp on
                 the strip (the Shopee shape): global maintenance, page
                 furniture. Add products (#201, Draft C - the owner 2026-09-05) is
                 the one way a main-catalogue product joins this store: a
                 primary button, sized to how often it happens, opening the
                 slide-over in place. --}}
            <x-ui.button type="button" variant="primary" data-add-panel-open>
                <x-ui.icon name="plus" size="14" /> Add products
            </x-ui.button>
        </div>
    @endif
</div>

@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'TikTok Shop', 'raw' => $liveTabError, 'settingsRoute' => 'ext.tiktok.index']) The live tabs and statuses are unavailable right now; the list below is unaffected.</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    {{-- One strip (the Shopee shape, #201): "All" is this store's own list,
         the stored view, pushed or not; the rest narrow to what TikTok
         holds right now, from the mirror. The strip says when the mirror
         was refreshed, and carries the on-demand refresh and the link
         audit as page furniture. --}}
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
                <nav class="x-segment" aria-label="TikTok Shop listing status">
                    <a href="{{ route('ext.tiktok.products.index', $tabQuery) }}"
                       class="x-segment__item {{ $tiktokTab === 'all' ? 'is-active' : '' }}"
                       @if($tiktokTab === 'all') aria-current="true" @endif>
                        <span>All</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                    </a>
                    @foreach($tabDefs as $tabKey => [$tabLabel, $tabStatus])
                        @continue($tabKey === 'all')
                        @php $isActive = $tiktokTab === $tabKey; @endphp
                        <a href="{{ route('ext.tiktok.products.index', array_merge($tabQuery, ['tiktok_tab' => $tabKey])) }}"
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
                @if($canManage)
                    {{-- The one button (the owner, 2026-09-07): Refresh checks every link against
                         the store and re-reads every status, the old Repair links folded in. --}}
                    <form method="POST" action="{{ route('ext.tiktok.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from TikTok Shop</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

@include('partials.channel-state-filter', [
    'bucket' => $troubleFilter ?? null,
    'count' => $products->total(),
    'clear' => route('ext.tiktok.products.index', request()->except(['state', 'page'])),
])

<form method="GET" action="{{ route('ext.tiktok.products.index') }}" class="x-filters">
    @if($tiktokTab !== 'all')
        <input type="hidden" name="tiktok_tab" value="{{ $tiktokTab }}">
    @endif
    <x-ui.input type="search" name="q" class="x-filters__search" value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search listings" />
    <div class="x-select-wrap x-filters__select">
        <select name="manufacturer" class="x-input" data-autosubmit aria-label="Filter by manufacturer">
            <option value="all">All manufacturers</option>
            @foreach($allManufacturers as $mId => $mName)
                <option value="{{ $mId }}" @selected($manufacturerFilter === (string) $mId)>{{ $mName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select">
        <select name="group" class="x-input" data-autosubmit aria-label="Filter by product group">
            <option value="all" @selected($groupFilter === 'all')>All product groups</option>
            @foreach($allGroups as $gId => $gName)
                <option value="{{ $gId }}" @selected($groupFilter === (string) $gId)>{{ $gName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalog status">
            <option value="all" @selected($erpStatus === 'all')>Any catalog status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="sync_status" class="x-input" data-autosubmit aria-label="Filter by TikTok listing status">
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="listed" @selected($syncStatus === 'listed')>Listed on TikTok Shop</option>
            <option value="not_listed" @selected($syncStatus === 'not_listed')>Not listed</option>
            <option value="error" @selected($syncStatus === 'error')>Has an error</option>
            <option value="ready" @selected($syncStatus === 'ready')>Ready to push</option>
            <option value="not_ready" @selected($syncStatus === 'not_ready')>Not ready to push</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasFilters)
        <a class="x-filters__reset" href="{{ route('ext.tiktok.products.index') }}">Clear</a>
    @endif
</form>

@if($canManage)
@php
    $bulk = [
        /* Multi-tick push (2026-09-09): each ticked product goes up on
           its own settings and reports for itself. */
        'push' => ['action' => route('ext.tiktok.products.bulk_push'), 'label' => 'Push to TikTok Shop',
            'confirm' => 'Push the selected products to TikTok Shop? Each goes up on its own settings, and anything not ready is named rather than sent.'],
        'switch' => [
            ['action' => route('ext.tiktok.products.bulk_toggle'), 'verb' => 'deactivate', 'codes' => 'ACTIVATE', 'label' => 'Deactivate :n on TikTok Shop', 'button' => 'Deactivate on TikTok Shop', 'fields' => ['action' => 'deactivate'], 'confirm' => 'Deactivate the selected products on TikTok Shop? Buyers stop seeing them. They can be activated again at any time.'],
            ['action' => route('ext.tiktok.products.bulk_toggle'), 'verb' => 'activate', 'codes' => 'SELLER_DEACTIVATED', 'label' => 'Activate :n on TikTok Shop', 'button' => 'Activate on TikTok Shop', 'fields' => ['action' => 'activate'], 'confirm' => 'Activate the selected products on TikTok Shop? Buyers see them again once TikTok applies it.'],
        ],
        'stock' => ['action' => route('ext.tiktok.products.bulk_push_stock')],
        'prices' => ['action' => route('ext.tiktok.products.bulk_push_price')],
        'link' => ['action' => route('ext.tiktok.products.check'), 'label' => 'Link IDs'],
        'delete' => ['action' => route('ext.tiktok.products.bulk_delete'), 'label' => 'Delete from TikTok Shop', 'confirm' => 'Delete the selected products from TikTok Shop? They are removed at the marketplace and this cannot be undone.'],
        'remove' => ['action' => route('ext.tiktok.products.bulk_remove_from_store'), 'label' => 'Remove from this channel', 'confirm' => 'Remove the selected products from this channel? They leave this store\'s list and stop syncing. Anything already on TikTok Shop stays up until you delete it there.'],
    ];
@endphp
@include('partials.channel-bulk-bar', ['bulk' => $bulk])
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : ($tiktokTab !== 'all' ? 'Nothing holds this status' : 'No products on this store yet') }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the product group and status filters.'
                    : ($tiktokTab !== 'all'
                        ? 'No listing on this store holds this status right now.'
                        : 'This store carries nothing yet. Add products from your main catalogue and they will land here, ready to configure and push to TikTok Shop.')">
        {{-- A new store opens here empty (#201): the way forward is Add
             products, not a dead end. The other two branches keep the reader
             from being stuck behind a filter with no visible way out. --}}
        @if($hasFilters)
        <x-slot:action>
            <x-ui.button :href="route('ext.tiktok.products.index')">Clear filters</x-ui.button>
        </x-slot:action>
        @elseif($tiktokTab !== 'all')
        <x-slot:action>
            <x-ui.button :href="route('ext.tiktok.products.index')">Show every status</x-ui.button>
        </x-slot:action>
        @elseif($canManage)
        <x-slot:action>
            <x-ui.button variant="primary" type="button" data-add-panel-open>Add products</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
@php $colCount = 8 + ($canManage ? 1 : 0); @endphp
<x-ui.table id="tiktok-listings-table">
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
            <th scope="col" class="cc-col-chanid">TikTok ID</th>
            @include('partials.channel-ready-head')
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pid = (int) $p->product_id;
            $listing = $listingsByProductId->get($pid);
            $ttId = $listing?->tiktok_product_id ?: ($pivotTruth->get($pid)?->tiktok_product_id);
            $listed = (bool) $ttId;
            $attempt = $lastAttempts->get($pid);
            $pGroups = $groupsByProductId->get($pid, []);
            $optRows = $optionRowsByProductId->get($pid);
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
            /* The mirrored status for this row; the strip says when it was
               refreshed. Never refreshed reads as exactly that, not as a guess. */
            $rowLiveStatus = null;
            if ($listed && $liveCounts !== null) {
                $rowLiveStatus = $liveStatuses[(string) $ttId] ?? null;
            }
            /* ONE state per row (Phase D, #184): the engine's answer feeds
               the badge, the drift chip AND the action menu (#188), so the
               words and the buttons can never disagree. The blade's private
               label map - built here out of STATUS_MAP plus its own MISSING
               entry - is gone. */
            $rowState = ($listingStates ?? [])[$pid] ?? null;
            $stateClass = \App\Integrations\Listings\ListingState::class;
            $attemptFailed = $attempt && $attempt->sync_status === 'error';
            $pName = (string) ($rowTitles[(int) $pid] ?? $p->name);
            $rowLabel = $pName !== '' ? $pName : (string) ($p->sku ?: 'product ' . $pid);
        @endphp
        @include('partials.channel-error-row', ['error' => ($rowErrors ?? [])[(int) $pid] ?? null, 'cols' => $colCount])
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
                        <a class="x-row-link co-item__name" href="{{ route('ext.tiktok.listings.edit', $pid) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: 'No SKU' }}</span>
                            @if($p->manufacturer_name)<span>{{ $p->manufacturer_name }}</span>@endif
                            @if(count($pGroups) > 0)
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Product Groups: {{ implode(', ', $pGroups) }}" @endif>Product group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>
            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cc-col-chanid" data-label="TikTok ID">
                @if($listed)<span class="x-num">{{ $ttId }}</span>@else<span class="x-cell-muted">None</span>@endif
            </td>
            <td class="cc-col-ready" data-label="Ready">
                @include('partials.channel-ready', ['state' => ($listingStates ?? [])[(int) $p->product_id] ?? null])
            </td>
            <td class="cc-col-sync" data-label="Listing">
                {{-- The engine's answer, rendered verbatim. Never checked
                     reads as exactly that, not as a guess. --}}
                @if($rowState === null)
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN && $listed && ($liveCounts ?? null) === null)
                    {{-- No shop configured: the mirror cannot exist, and
                         "Listed" is all the link can honestly say. --}}
                    <x-ui.badge tone="success">Listed</x-ui.badge>
                @elseif($rowState->state === $stateClass::UNKNOWN)
                    <x-ui.badge tone="neutral" title="Press Refresh from TikTok Shop to read its status">{{ $rowState->label() }}</x-ui.badge>
                @elseif($rowState->state === $stateClass::NOT_LISTED)
                    <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    {{-- Whether a push would pass is the Ready column's. --}}
                @else
                    {{-- The title rides a wrapping span: Blade cannot compile
                         an @if inside a component tag's attribute list. --}}
                    <span @if($rowState->checkedAt) title="Checked {{ $rowState->checkedAt->diffForHumans() }}" @endif>
                        <x-ui.badge :tone="$rowState->tone()">{{ $rowState->label() }}</x-ui.badge>
                    </span>
                    {{-- Drift (#100), on this index for the first time: live
                         and out of date are different facts, both shown. --}}
                    @if($rowState->driftLabel())
                        <span class="cc-checked"><x-ui.badge tone="warning">{{ $rowState->driftLabel() }}</x-ui.badge>
                            <x-ui.hint label="What changed">{{ implode(' ', $rowState->reasons) }}</x-ui.hint></span>
                    @elseif($rowState->reasons)
                        <x-ui.hint label="What TikTok Shop says">{{ implode(' ', $rowState->reasons) }}</x-ui.hint>
                    @endif
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManage || $canEditCatalog)
                @php
                    $menu = [
                        'label' => "Product {$pid} actions",
                        'store' => 'TikTok Shop',
                        'edit' => route('ext.tiktok.listings.edit', $pid),
                        'catalog' => $canEditCatalog ? route('products.edit', $pid) : null,
                    ];
                    if ($canManage) {
                        if (!$listed) {
                            $menu['push'] = ['href' => route('ext.tiktok.listings.edit', [$pid, 'back' => request()->getRequestUri()]), 'label' => 'Push to TikTok Shop'];
                            $menu['link'] = ['action' => route('ext.tiktok.products.check'), 'fields' => ['product_ids[]' => $pid]];
                        } else {
                            $menu['update'] = ['action' => route('ext.tiktok.listings.push_update', $pid), 'label' => 'Update on TikTok Shop', 'confirm' => "Push this listing's content and settings onto the live TikTok Shop product? Title, description, package, category, brand, attributes, price and stock are updated.", 'verb' => 'Push update', 'tone' => 'primary'];
                            $menu['stock'] = route('ext.tiktok.products.push_stock', $pid);
                            $menu['price'] = route('ext.tiktok.products.push_price', $pid);
                            if ($rowState?->state === $stateClass::LIVE) {
                                $menu['switch'] = ['action' => route('ext.tiktok.listings.toggle', $pid), 'label' => 'Deactivate on TikTok Shop', 'fields' => ['action' => 'deactivate'], 'confirm' => 'Deactivate this product on TikTok Shop? Buyers stop seeing it. It can be activated again at any time.', 'verb' => 'Deactivate'];
                            } elseif ($rowState?->state === $stateClass::INACTIVE) {
                                $menu['switch'] = ['action' => route('ext.tiktok.listings.toggle', $pid), 'label' => 'Activate on TikTok Shop', 'fields' => ['action' => 'activate'], 'confirm' => 'Activate this product on TikTok Shop? Buyers see it again once TikTok applies it.', 'verb' => 'Activate', 'tone' => 'primary'];
                            }
                            $menu['check'] = ['action' => route('ext.tiktok.products.check'), 'label' => 'Check against TikTok Shop', 'fields' => ['product_ids[]' => $pid]];
                            $menu['unlink'] = ['action' => route('ext.tiktok.products.unlink', $pid), 'confirm' => 'Unlink this product from TikTok Shop? Only the link is removed. The TikTok Shop product stays up and can be linked again later.'];
                            $menu['delete'] = ['action' => route('ext.tiktok.products.delete', $pid), 'confirm' => 'Delete this product from TikTok Shop? It is removed at the marketplace and this cannot be undone.'];
                        }
                        $menu['remove'] = ['action' => route('ext.tiktok.products.remove_from_store', $pid), 'label' => 'Remove from this channel', 'verb' => 'Remove', 'confirm' => $listed
                            ? "Remove {$rowLabel} from this channel? It leaves this store's list and stops syncing. It is on TikTok Shop (product {$ttId}) and stays up, still orderable, until you remove it there. Use Delete from TikTok Shop for that."
                            : "Remove {$rowLabel} from this channel? It leaves this store's list and stops syncing. Nothing on TikTok Shop is changed."];
                    }
                @endphp
                @include('partials.channel-row-menu', ['menu' => $menu])
                @endif
            </td>
        </tr>
        @if($optRows && count($optRows) > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManage, 'trail' => ['cc-col-chanid', 'cc-col-ready', 'cc-col-sync', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

<div id="tiktok-listings-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="tiktok-listings-progress-title" aria-describedby="tiktok-listings-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="tiktok-listings-progress-title" data-progress-title>Talking to TikTok Shop</div>
                <div class="cc-progress__note" id="tiktok-listings-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

{{-- The Add products slide-over (#201, Draft C), manage-gated like its
     button and every route it calls. Each addition joins this store's list
     (unpushed, the push workflow unchanged) and lands in the table behind
     when the panel closes; the full catalogue page is the overflow. --}}
@if($canManage)
    <x-channel.add-panel
        id="tiktok-add-panel"
        :search-url="route('ext.tiktok.products.catalogue_search')"
        :add-url="route('ext.tiktok.products.add_to_store', ['productId' => '__PID__'])"
        sub="Search your main catalogue. Each product you add joins this store's list, ready to configure and push to TikTok Shop."
        toggle-label="Show products already on this store"
        in-label="On this store"
        scope="this store"
        empty-default="Every enabled product is already on this store."
        empty-all="Your catalogue has no enabled products."
        list-label="Catalogue products"
        :full-url="route('ext.tiktok.products.index', ['list' => 'add'])" />
@endif

</div>{{-- /.cc-page --}}
@endsection
