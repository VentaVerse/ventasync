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

    $hasFilters = $q !== '' || $groupFilter !== 'all' || $erpStatus !== 'all' || $syncStatus !== 'all';
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
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product matches' : 'products match' }}
            @else
                {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in scope for {{ $storeName }}
            @endif
        </p>
    </div>
    @if(auth()->user()?->hasPermission('manage_venta/listing') ?? false)
        <div class="cc-head-actions">
            {{-- The product group page's check, page-wide (#184's one-workbench
                 rule, now on Venta too): with no rows ticked it walks
                 every linked product, 200 at a time. --}}
            <form method="POST" action="{{ route('ext.venta.listings.check', $setting->id) }}"
                  data-confirm="Check every linked product against {{ $storeName }}? Nothing is sent or changed on {{ $storeName }}; the ERP updates its own records to match what it finds, 200 products at a time." data-confirm-tone="primary" data-confirm-verb="Check">
                @csrf
                <x-ui.button type="submit" variant="secondary">Check against {{ $storeName }}</x-ui.button>
            </form>
        </div>
    @endif
</div>

{{-- Two questions on one strip, each named (the owner, 2026-08-28): the
     catalogue's own total, whether pushed or not, and the store's buckets
     from the mirror. The strip says when the mirror was refreshed. --}}
<div class="x-segment-bar">
    <div class="x-segment-groups">
        <div class="x-segment-group">
            <span class="x-segment-group__k">Your catalogue</span>
            <nav class="x-segment" aria-label="Catalogue">
                <a href="{{ $indexUrl($tabQuery) }}"
                   class="x-segment__item {{ $ventaTab === 'all' ? 'is-active' : '' }}"
                   @if($ventaTab === 'all') aria-current="true" @endif>
                    <span>All products</span>
                    <span class="x-segment__count">{{ number_format((int) $catalogueTotal) }}</span>
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
                <form method="POST" action="{{ route('ext.venta.listings.refresh_status', $setting->id) }}" class="x-segment-group__refresh" data-slow-action>
                    @csrf
                    <x-ui.button type="submit" size="sm">Refresh from {{ $storeName }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>
</div>

<form method="GET" action="{{ $indexUrl() }}" class="x-filters">
    @if($ventaTab !== 'all')
        <input type="hidden" name="venta_tab" value="{{ $ventaTab }}">
    @endif
    <x-ui.input type="search" name="q" class="x-filters__search" value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search listings" />
    <div class="x-select-wrap x-filters__select">
        <select name="group" class="x-input" data-autosubmit aria-label="Filter by product group">
            <option value="all" @selected($groupFilter === 'all')>All groups</option>
            @foreach($allGroups as $gId => $gName)
                <option value="{{ $gId }}" @selected($groupFilter === (string) $gId)>{{ $gName }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalogue status">
            <option value="all" @selected($erpStatus === 'all')>Any catalogue status</option>
            <option value="enabled" @selected($erpStatus === 'enabled')>Enabled</option>
            <option value="disabled" @selected($erpStatus === 'disabled')>Disabled</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="sync_status" class="x-input" data-autosubmit aria-label="Filter by store listing status">
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="listed" @selected($syncStatus === 'listed')>On {{ $storeName }}</option>
            <option value="not_listed" @selected($syncStatus === 'not_listed')>Not listed</option>
            <option value="error" @selected($syncStatus === 'error')>Last push failed</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>
    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $indexUrl() }}">Clear</a>
    @endif
</form>

@if($canManage && $setting->enabled)
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected <span class="cc-bulkbar__note" data-bulk-skipped></span></span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.venta.listings.check', $setting->id) }}" data-bulk-form>
            @csrf
            <x-ui.button type="submit" size="sm">Check against {{ $storeName }}</x-ui.button>
        </form>
        {{-- Unlist / Relist for the selection, in the store's own terms.
             Each form acts only on the ticked rows its verb applies to, per
             the mirrored status; the count is in the label and the rest of
             the selection is named as skipped beside the count. --}}
        <form method="POST" action="{{ route('ext.venta.listings.bulk_toggle', $setting->id) }}" data-bulk-form
              data-bulk-verb="unlist" data-verb-codes="active" data-verb-label="Unlist :n on {{ $storeName }}"
              data-bulk-confirm="Unlist the selected products on {{ $storeName }}? Buyers stop seeing them. They can be relisted at any time." hidden>
            @csrf
            <input type="hidden" name="action" value="unlist">
            <x-ui.button type="submit" size="sm">Unlist on {{ $storeName }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.venta.listings.bulk_toggle', $setting->id) }}" data-bulk-form
              data-bulk-verb="relist" data-verb-codes="inactive" data-verb-label="Relist :n on {{ $storeName }}"
              data-bulk-confirm="Relist the selected products on {{ $storeName }}? Buyers see them again at once." hidden>
            @csrf
            <input type="hidden" name="action" value="relist">
            <x-ui.button type="submit" size="sm">Relist on {{ $storeName }}</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in scope yet' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the group and status filters.'
                    : 'This list shows catalogue products that belong to a product group on ' . $storeName . ', plus anything already on the store. Create a product group to bring products into scope.'">
        <x-slot:action>
            @if($hasFilters)
                <x-ui.button :href="$indexUrl()">Clear filters</x-ui.button>
            @else
                <x-ui.button :href="route('ext.venta.product-groups.index', $setting->id)">Go to Product Groups</x-ui.button>
            @endif
        </x-slot:action>
    </x-ui.empty>
@else
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
            <th scope="col" class="cc-col-chanid">Store ID</th>
            <th scope="col" class="cc-col-sync">Listing</th>
            <th scope="col" class="cc-col-err">Last sync</th>
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
                        <a class="x-row-link co-item__name" href="{{ $listingUrl }}">{{ $p->name ?: 'Unnamed product' }}</a>
                        <div class="co-item__meta">
                            <span class="co-item__sku">{{ $p->sku ?: ($p->model ?: 'No SKU') }}</span>
                            @if($p->manufacturer_name)<span>{{ $p->manufacturer_name }}</span>@endif
                            @if(count($pGroups) > 0)
                                <span class="co-item__var" @if(count($pGroups) > 1) title="Groups: {{ implode(', ', $pGroups) }}" @endif>Group{{ count($pGroups) > 1 ? 's' : '' }}: {{ $pGroups[0] }}@if(count($pGroups) > 1) and {{ count($pGroups) - 1 }} more @endif</span>
                            @endif
                        </div>
                    </div>
                </div>
            </td>
            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ (int) $p->quantity }}</span></td>
            <td class="cc-col-price x-td-num" data-label="Price"><x-money :php="(float) $p->price" /></td>
            <td class="cc-col-chanid" data-label="Store ID">
                @if($listed)<span class="x-num">{{ $link->venta_product_id }}</span>@else<span class="x-cell-muted">None</span>@endif
            </td>
            <td class="cc-col-sync" data-label="Listing">
                @if($rowLiveStatus !== null && isset($statusMap[$rowLiveStatus]))
                    <x-ui.badge :tone="$statusMap[$rowLiveStatus][1]">{{ $statusMap[$rowLiveStatus][0] }}</x-ui.badge>
                @elseif($listed)
                    <x-ui.badge tone="neutral" title="Press Refresh from {{ $storeName }} to read its status">Not checked yet</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
                @endif
            </td>
            <td class="cc-col-err" data-label="Last sync">
                {{-- The shared Last sync cell (partials.channel-last-sync), the
                     drawing every other Listings page uses: when on one line,
                     what it was on a caption (its listing, or a group push). --}}
                @php
                    if ($attemptFailed) {
                        $ventaSync = ['tone' => 'failed', 'headline' => 'Last push' . ($attempt->last_pushed_at ? ' ' . $attempt->last_pushed_at->diffForHumans() : '') . ' failed:', 'detail' => (string) $attempt->push_error, 'at' => $attempt->last_pushed_at];
                    } elseif ($listing?->last_pushed_at) {
                        $ventaSync = ['tone' => 'ok', 'headline' => 'Synced ' . $listing->last_pushed_at->diffForHumans(), 'what' => 'listing', 'stale' => false, 'detail' => null, 'at' => $listing->last_pushed_at];
                    } elseif ($attempt?->last_pushed_at) {
                        $ventaSync = ['tone' => 'ok', 'headline' => 'Synced ' . $attempt->last_pushed_at->diffForHumans(), 'what' => 'group push', 'stale' => false, 'detail' => null, 'at' => $attempt->last_pushed_at];
                    } else {
                        $ventaSync = ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null];
                    }
                @endphp
                @include('partials.channel-last-sync', ['sync' => $ventaSync])
            </td>
            <td class="x-td-actions">
                @if($canManage || $canEditCatalog)
                <x-ui.menu label="Product {{ $pid }} actions">
                    <a class="x-menu__item" href="{{ $listingUrl }}">Open listing</a>
                    @if($canEditCatalog)<a class="x-menu__item" href="{{ route('products.edit', $pid) }}">Edit in catalog</a>@endif
                    @if($canManage && $setting->enabled)
                        @if(!$listed)
                            {{-- One push, and it opens the listing page first
                                 (#149), where "Push to the store" sits under
                                 what is about to go up. --}}
                            <a class="x-menu__item" href="{{ $listingUrl }}">Push to {{ $storeName }}</a>
                        @else
                            <form method="POST" action="{{ route('ext.venta.listings.push', [$setting->id, $pid]) }}" data-slow-action
                                  data-confirm="Push this listing's content and price rule onto the live {{ $storeName }} product? Name, description, price, stock, images and variations are updated in place." data-confirm-tone="primary" data-confirm-verb="Push update">
                                @csrf
                                <button type="submit" class="x-menu__item">Update on {{ $storeName }}</button>
                            </form>
                            @if($rowLiveStatus === \Extensions\venta\Models\VentaListing::STATUS_ACTIVE)
                                <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $pid]) }}" data-slow-action
                                      data-confirm="Unlist this product on {{ $storeName }}? Buyers stop seeing it. It can be relisted at any time." data-confirm-verb="Unlist">
                                    @csrf
                                    <input type="hidden" name="action" value="unlist">
                                    <button type="submit" class="x-menu__item">Unlist on {{ $storeName }}</button>
                                </form>
                            @elseif($rowLiveStatus === \Extensions\venta\Models\VentaListing::STATUS_INACTIVE)
                                <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $pid]) }}" data-slow-action
                                      data-confirm="Relist this product on {{ $storeName }}? Buyers see it again at once." data-confirm-tone="primary" data-confirm-verb="Relist">
                                    @csrf
                                    <input type="hidden" name="action" value="relist">
                                    <button type="submit" class="x-menu__item">Relist on {{ $storeName }}</button>
                                </form>
                            @endif
                        @endif
                    @endif
                </x-ui.menu>
                @endif
            </td>
        </tr>
        @if($optRows && count($optRows) > 0)
            {{-- The variations as rows of this table, under the same headers (partials.channel-variation-rows). --}}
            @include('partials.channel-variation-rows', ['rows' => $optRows, 'check' => $canManage, 'trail' => ['cc-col-chanid', 'cc-col-sync', 'cc-col-err', 'x-td-actions']])
        @endif
    @endforeach
</x-ui.table>

<x-ui.pager :paginator="$products" />
@endif

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

</div>
@endsection
