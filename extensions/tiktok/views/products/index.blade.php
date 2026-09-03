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
            @if($hasFilters)
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in your catalogue
                &middot; {{ number_format($listedTotal ?? 0) }} listed on TikTok Shop
            @endif
        </p>
    </div>
    @if($canManage)
        <div class="cc-head-actions">
            {{-- The group page's check, page-wide (Phase F, #184): with no
                 rows ticked it walks every linked product. --}}
            <form method="POST" action="{{ route('ext.tiktok.products.check') }}"
                  data-confirm="Check every linked product against TikTok Shop? Nothing is sent or changed on TikTok Shop; the ERP updates its own records to match what it finds." data-confirm-tone="primary" data-confirm-verb="Check against TikTok Shop">
                @csrf
                <x-ui.button type="submit" variant="secondary">Check against TikTok Shop</x-ui.button>
            </form>
        </div>
    @endif
</div>

@if($canManage)

@endif

@if(($liveTabError ?? null) !== null)
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'TikTok Shop', 'raw' => $liveTabError, 'settingsRoute' => 'ext.tiktok.index']) The live tabs and statuses are unavailable right now; the catalog below is unaffected.</div>
    </div>
@elseif(($liveCounts ?? null) !== null)
    {{-- Two questions on one strip, each named (the owner, 2026-08-28): the
         catalogue's own total, whether pushed or not, and TikTok's buckets
         from the mirror. The strip says when the mirror was refreshed. --}}
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
                <span class="x-segment-group__k">Your catalogue</span>
                <nav class="x-segment" aria-label="Catalogue">
                    <a href="{{ route('ext.tiktok.products.index', $tabQuery) }}"
                       class="x-segment__item {{ $tiktokTab === 'all' ? 'is-active' : '' }}"
                       @if($tiktokTab === 'all') aria-current="true" @endif>
                        <span>All products</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                    </a>
                </nav>
            </div>
            <div class="x-segment-group">
                <span class="x-segment-group__k">On TikTok Shop</span>
                <nav class="x-segment" aria-label="TikTok Shop listing status">
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
                    <form method="POST" action="{{ route('ext.tiktok.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit" size="sm">Refresh from TikTok Shop</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

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
            <option value="error" @selected($syncStatus === 'error')>Last push failed</option>
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
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected <span class="cc-bulkbar__note" data-bulk-skipped></span></span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.tiktok.products.bulk_send') }}" data-bulk-form
              data-bulk-confirm="Send the selected products to TikTok Shop? New products are created; anything already listed gets its content updated in place.">
            @csrf
            <x-ui.button type="submit" size="sm">Push to TikTok Shop</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.products.check') }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Check against TikTok Shop</x-ui.button>
        </form>
        {{-- Deactivate / Activate for the selection (the owner, 2026-08-28). Each
             form acts only on the ticked rows its verb applies to, per the
             mirrored status; the count is in the label and the rest of the
             selection is named as skipped beside the count. --}}
        <form method="POST" action="{{ route('ext.tiktok.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="deactivate" data-verb-codes="ACTIVATE" data-verb-label="Deactivate :n on TikTok Shop"
              data-bulk-confirm="Deactivate the selected products on TikTok Shop? Buyers stop seeing them. They can be activated again at any time." hidden>
            @csrf
            <input type="hidden" name="action" value="deactivate">
            <x-ui.button type="submit" size="sm">Deactivate on TikTok Shop</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.products.bulk_toggle') }}" data-bulk-form
              data-bulk-verb="activate" data-verb-codes="SELLER_DEACTIVATED" data-verb-label="Activate :n on TikTok Shop"
              data-bulk-confirm="Activate the selected products on TikTok Shop? Buyers see them again once TikTok applies it." hidden>
            @csrf
            <input type="hidden" name="action" value="activate">
            <x-ui.button type="submit" size="sm">Activate on TikTok Shop</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products yet' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the product group and status filters.'
                    : 'Every catalog product appears here, ready to list on TikTok Shop. Add your first product and it will be waiting.'">
        <x-slot:action>
            @if($hasFilters)
                <x-ui.button :href="route('ext.tiktok.products.index')">Clear filters</x-ui.button>
            @else
                <x-ui.button :href="route('products.create')">Add a product</x-ui.button>
            @endif
        </x-slot:action>
    </x-ui.empty>
@else
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
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="cc-col-err">Last push</th>
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
        @endphp
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
                        <a class="x-row-link co-item__name" href="{{ route('ext.tiktok.listings.edit', $pid) }}">{{ $p->name ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: 'No SKU' }}</span>
                            @if($p->manufacturer_name)<span>{{ $p->manufacturer_name }}</span>@endif
                            @if(count($pGroups) > 0)
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Product Groups: {{ implode(', ', $pGroups) }}" @endif>Product group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>
                        @if($optRows && count($optRows) > 0)
                            <div class="cc-vars">
                                @foreach($optRows as $or)
                                    @php
                                        $oimg = trim((string) ($or->option_image ?? ''));
                                        $osrc = $oimg !== '' ? asset('storage/' . ltrim($oimg, '/')) : '';
                                        $oprice = (float) ($or->absolute_price ?? 0);
                                    @endphp
                                    <div class="cc-var">
                                        <span class="cc-var__thumb">@if($osrc)<img src="{{ $osrc }}" alt="" loading="lazy" decoding="async">@endif</span>
                                        <span class="cc-var__name">{{ (string) ($or->option_value_name ?? '') !== '' ? $or->option_value_name : 'Unnamed variation' }}</span>
                                        <span class="cc-var__sku">{{ (string) ($or->sku ?? '') }}</span>
                                        <span class="cc-var__qty">{{ (int) ($or->quantity ?? 0) }} in stock</span>
                                        <span class="cc-var__price">{{ $oprice > 0 ? \App\Support\Money::base($oprice) : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </td>
            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cc-col-chanid" data-label="TikTok ID">
                @if($listed)<span class="x-num">{{ $ttId }}</span>@else<span class="x-cell-muted">None</span>@endif
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
                    @if($rowState->ready)
                        <span class="cc-checked">Ready to push</span>
                    @else
                        <span class="cc-checked"><x-ui.badge tone="warning">Not ready</x-ui.badge>
                            <x-ui.hint label="What is missing before a push">Missing: {{ $rowState->missingSummary() }}</x-ui.hint></span>
                    @endif
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
            <td class="cc-col-err" data-label="Last push">
                @if($attemptFailed)
                    <span class="cc-err">{{ \Illuminate\Support\Str::limit((string) $attempt->push_error, 120) }}</span>
                    @if($attempt->last_pushed_at)<span class="cc-when">{{ $attempt->last_pushed_at->diffForHumans() }}</span>@endif
                @elseif($listing?->last_pushed_at)
                    <span class="x-cell-muted">Pushed</span>
                    <span class="cc-when">{{ $listing->last_pushed_at->diffForHumans() }}{{ $listing->last_push_source ? ' from ' . (str_starts_with($listing->last_push_source, 'group:') ? 'the ' . substr($listing->last_push_source, 6) . ' product group' : 'its listing') : '' }}</span>
                @elseif($attempt?->last_pushed_at)
                    <span class="x-cell-muted">Pushed</span>
                    <span class="cc-when">{{ $attempt->last_pushed_at->diffForHumans() }}</span>
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>
            <td class="x-td-actions">
                @if($canManage || $canEditCatalog)
                <x-ui.menu label="Product {{ $pid }} actions">
                    <a class="x-menu__item" href="{{ route('ext.tiktok.listings.edit', $pid) }}">Open listing</a>
                    @if($canEditCatalog)<a class="x-menu__item" href="{{ route('products.edit', $pid) }}">Edit in catalog</a>@endif
                    @if($canManage)
                        @if(!$listed)
                            {{-- One push, and it opens the listing page first
                                 (#149), where "Push to TikTok Shop" sits under
                                 what is about to go up. The one-click is gone
                                 from the menu; the route stays for the page. --}}
                            <a class="x-menu__item" href="{{ route('ext.tiktok.listings.edit', $pid) }}">Push to TikTok Shop</a>
                        @else
                            <form method="POST" action="{{ route('ext.tiktok.listings.push_update', $pid) }}" data-slow-action
                                  data-confirm="Push this listing's content and settings onto the live TikTok Shop product? Title, description, package, category, brand and attributes are updated. Price and stock are not touched." data-confirm-tone="primary" data-confirm-verb="Push update">
                                @csrf
                                <button type="submit" class="x-menu__item">Update on TikTok Shop</button>
                            </form>
                            <form method="POST" action="{{ route('ext.tiktok.products.push_stock', $pid) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push stock</button>
                            </form>
                            <form method="POST" action="{{ route('ext.tiktok.products.push_price', $pid) }}" data-slow-action>
                                @csrf
                                <button type="submit" class="x-menu__item">Push price</button>
                            </form>
                            {{-- The marketplace's own on/off, from the state
                                 the badge wears (#188) - not the raw string,
                                 which also went silent whenever the tab strip
                                 was hidden. --}}
                            @if($rowState?->state === $stateClass::LIVE)
                                <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $pid) }}" data-slow-action
                                      data-confirm="Deactivate this product on TikTok Shop? Buyers stop seeing it. It can be activated again at any time." data-confirm-verb="Deactivate">
                                    @csrf
                                    <input type="hidden" name="action" value="deactivate">
                                    <button type="submit" class="x-menu__item">Deactivate on TikTok Shop</button>
                                </form>
                            @elseif($rowState?->state === $stateClass::INACTIVE)
                                <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $pid) }}" data-slow-action
                                      data-confirm="Activate this product on TikTok Shop? Buyers see it again once TikTok applies it." data-confirm-tone="primary" data-confirm-verb="Activate">
                                    @csrf
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="x-menu__item">Activate on TikTok Shop</button>
                                </form>
                            @endif
                            <div class="x-menu__sep"></div>
                            <form method="POST" action="{{ route('ext.tiktok.products.unlink', $pid) }}" data-slow-action
                                  data-confirm="Unlink this product from TikTok Shop? Only the link is removed. The TikTok Shop product stays up and can be matched again later." data-confirm-verb="Unlink">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from TikTok Shop</button>
                            </form>
                            <form method="POST" action="{{ route('ext.tiktok.products.delete', $pid) }}" data-slow-action
                                  data-confirm="Delete this product from TikTok Shop? It is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from TikTok Shop">
                                @csrf
                                <button type="submit" class="x-menu__item x-menu__item--danger">Delete from TikTok Shop</button>
                            </form>
                        @endif
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
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

</div>
@endsection
