@extends('layouts.channel')
@section('title', $group->name . ' Products, Lazada')
@section('breadcrumb', $group->name . ' Products')

{{-- Round F Task 1. Rebuilt on the shared cc- list anatomy
     (pages/channel-catalog.css), the same one Venta's and OpenCart's identical
     pages already use, with the behaviour moved out of an inline <script> into
     resources/js/pages/group-products.js.

     What changed and why, beyond the drawing:

       - the per-row dropdown of up to seven controls was a hand-rolled popup
         built from inline styles with its own outside-click handler. It is
         <x-ui.menu> now, which Alpine drives, and the row itself is clickable
         through to the catalogue product;
       - the standing accent-tinted strip of five buttons is a bulk bar that
         does not exist until at least one row is ticked;
       - Delete from Lazada was a solid red button standing on the page. It is
         in the bulk bar, at transparent danger weight, behind a confirmation;
       - the `_return` field used to be injected into every POST form by a
         script at load. It is a real hidden input now, the same value, without
         depending on JavaScript having run;
       - `$canManageLazada` was hardcoded true, so a view_lazada operator was
         shown a dozen controls the routes would refuse.

     The wire is unchanged: the same `ids[]`, the same `_return`, the same
     routes, the same GET filter parameters, the same search endpoint and the
     same two-character minimum. --}}
@section('content')
@php
    // Read tier on this route is `view_lazada|manage_lazada`; every push,
    // sync, link, unlink and remove below is `manage_lazada`.
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada') ?? false;

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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this group on Lazada
        </p>
    </div>
    @if($canManageLazada)
        <x-ui.button :href="route('ext.lazada.product-groups.edit', $group->id)">Edit group</x-ui.button>
    @endif
</div>

{{-- One filter row. The two selects submit on change (data-autosubmit,
     resources/js/list-filters.js) and Clear is a link, because it is
     navigation and not an action. Every parameter name is unchanged. --}}
<form method="GET" action="{{ $listUrl }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search products in this group" />

    <div class="x-select-wrap x-filters__select x-filters__select--narrow">
        <select name="erp_status" class="x-input" data-autosubmit aria-label="Filter by catalogue status">
            <option value="all" @selected($erpStatus === 'all')>Any catalogue status</option>
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
            <option value="error" @selected($syncStatus === 'error')>Last push failed</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $listUrl }}">Clear</a>
    @endif
</form>

@if($canManageLazada)
{{-- Bulk bar. It exists only once at least one row is ticked. Each action is
     still its own form posting `ids[]` to its own route; the module injects
     the ticked ids into whichever form is submitted.

     Delete from Lazada carries data-bulk-confirm rather than data-confirm.
     The old page carried both a data-confirm attribute AND a confirmModal()
     call in its own submit handler, so the operator could be asked twice for
     the same deletion. One mechanism, one question. --}}
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar>
    <span class="cc-bulkbar__count"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.lazada.product-groups.push', $group->id) }}" data-bulk-form
              data-bulk-confirm="Push the selected products to Lazada? Anything already pushed is skipped.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push to Lazada</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.lazada.product-groups.pushPrices', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.lazada.product-groups.pushStock', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.lazada.product-groups.massRemove', $group->id) }}" data-bulk-form
              data-bulk-confirm="Take the selected products out of this group? Nothing is deleted from the catalogue, and nothing already live on Lazada is touched.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Remove from group</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.lazada.product-groups.deleteFromLazada', $group->id) }}" data-bulk-form
              data-bulk-confirm="Delete the selected products from Lazada? They are removed at the marketplace and this cannot be undone.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" variant="danger" size="sm">Delete from Lazada</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalogue and push filters.'
                    : 'Edit the group to pick products from the catalogue, or add them one at a time below.'">
        @if($canManageLazada && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.lazada.product-groups.edit', $group->id)">Edit group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageLazada)
            <th class="cc-col-check">
                <input type="checkbox" class="cc-check"
                       :checked="ids.length > 0 && selected.length === ids.length"
                       x-effect="$el.indeterminate = selected.length > 0 && selected.length < ids.length"
                       @change="selected = $event.target.checked ? ids.slice() : []"
                       aria-label="Select every product on this page">
            </th>
            @endif
            <th class="cc-col-id">ID</th>
            <th>Product</th>
            <th class="cc-col-qty x-td-num">Stock</th>
            <th class="cc-col-price x-td-num">Price</th>
            <th class="cc-col-chanid">Lazada ID</th>
            <th class="cc-col-stat">Catalogue</th>
            <th class="cc-col-sync">Push</th>
            <th class="cc-col-err">Last push</th>
            <th class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pivot = $pivotMap->get($p->product_id);

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : null;

            $syncState = $pivot->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked';
            // Lazada's marketplace id is selected onto the PRODUCT row by
            // LazadaProductGroupController::products(), not carried on the
            // pivot the way Shopee's and TikTok's are. The pivot table has no
            // lazada_item_id column at all, so reading it there is silently
            // null and every row reports "not pushed". Same expression the
            // pre-redesign view used.
            $hasLazadaId = !empty($p->lazada_item_id) && !$isUnlinked;
            $failed = $syncState === 'error';
            $hasError = $failed && ($pivot->push_error ?? null);

            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $pName = html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pSku = (string) ($p->sku ?: ($p->model ?: ''));
            $isManual = in_array((int) $p->product_id, $manualIds, true);

            // Built here rather than as a run of adjacent @if directives in
            // the markup: Blade's directive pattern needs a non-word character
            // before an @, so `@endif@if(` would leave the second directive as
            // literal text in the page.
            $metaParts = [$pSku !== '' ? $pSku : 'No SKU'];
            if (!empty($p->manufacturer_name)) {
                $metaParts[] = (string) $p->manufacturer_name;
            }
            $metaParts[] = $isManual ? 'added by hand' : 'matched by the group filter';
            $meta = implode(' / ', $metaParts);
        @endphp
        <tr>
            @if($canManageLazada)
            <td class="cc-col-check">
                {{-- No name attribute: these boxes belong to no form. Each
                     action is its own form and the module injects the ticked
                     ids into whichever one is submitted, which is the
                     pre-redesign wire unchanged. --}}
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
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async">
                        @endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        {{-- SKU and manufacturer sit here rather than in
                             columns of their own: thirteen fixed columns left
                             the product name unreadable at 1440. --}}
                        <span class="co-item__meta">{{ $meta }}</span>

                        @if($variationCount > 0)
                            <div class="cc-vars">
                                @foreach($optRows as $or)
                                    @php
                                        $ovn = (string) ($or->option_value_name ?? '');
                                        $osku = (string) ($or->option_sku ?? '');
                                        $oqty = (int) ($or->option_quantity ?? 0);
                                        $oprice = (float) ($or->option_absolute_price ?? 0);
                                    @endphp
                                    <div class="cc-var">
                                        <span class="cc-var__name">{{ $ovn !== '' ? $ovn : 'Unnamed variation' }}</span>
                                        <span class="cc-var__sku">{{ $osku }}</span>
                                        <span class="cc-var__qty">{{ $oqty }} in stock</span>
                                        {{-- Never a hardcoded symbol: App\Support\Money reads the
                                             default currency off the currencies table. --}}
                                        <span class="cc-var__price">{{ $oprice > 0 ? \App\Support\Money::base($oprice) : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </td>

            <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ number_format((int) $p->quantity) }}</span></td>

            <td class="cc-col-price x-td-num" data-label="Price">
                <x-money :php="(float) $p->price" />
            </td>

            <td class="cc-col-chanid" data-label="Lazada ID">
                @if($hasLazadaId)
                    <span class="x-num">{{ $p->lazada_item_id }}</span>
                @else
                    <span class="x-cell-muted">Not pushed</span>
                @endif
            </td>

            <td class="cc-col-stat" data-label="Catalogue">
                @if((int) $p->status === 1)
                    <span class="x-cell-strong">Enabled</span>
                @else
                    <span class="x-cell-muted">Disabled</span>
                @endif
            </td>

            <td class="cc-col-sync" data-label="Push">
                @if($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @elseif($hasLazadaId)
                    <x-ui.badge tone="success">Pushed</x-ui.badge>
                @elseif($failed)
                    <x-ui.badge tone="danger">Failed</x-ui.badge>
                @else
                    <span class="x-cell-muted">Not yet</span>
                @endif
            </td>

            <td class="cc-col-err" data-label="Last push">
                @if($hasError)
                    <span class="cc-err">{{ \Illuminate\Support\Str::limit($pivot->push_error, 120) }}</span>
                    @if($pivot->last_pushed_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasLazadaId && $pivot && ($pivot->last_pushed_at ?? null))
                    <span class="cc-when">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</span>
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageLazada)
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalogue</a>

                    @if(!$hasLazadaId)
                        <form method="POST" action="{{ route('ext.lazada.product-groups.syncId', [$group->id, $p->product_id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item">Match Lazada ID</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.push', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push to Lazada</button>
                        </form>
                    @endif

                    @if($hasLazadaId)
                        <form method="POST" action="{{ route('ext.lazada.product-groups.updateProduct', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Update on Lazada</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.pushStock', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push stock</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.pushPrices', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push price</button>
                        </form>
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.unlinkProduct', [$group->id, $p->product_id]) }}" data-slow-action
                              data-confirm="Unlink this product from Lazada? The product stays in the group but sync will stop.">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Lazada</button>
                        </form>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.deleteFromLazada', $group->id) }}" data-slow-action
                              data-confirm="Delete this product from Lazada? It is removed at the marketplace and this cannot be undone.">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Lazada</button>
                        </form>
                    @endif

                    @if($isUnlinked)
                        <form method="POST" action="{{ route('ext.lazada.product-groups.linkProduct', [$group->id, $p->product_id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item">Link to Lazada again</button>
                        </form>
                    @endif

                    @if($isManual)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.lazada.product-groups.removeProduct', [$group->id, $p->product_id]) }}"
                              data-confirm="Take this product out of the group? Nothing is deleted from the catalogue, and nothing already live on Lazada is touched.">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Remove from group</button>
                        </form>
                    @endif
                </x-ui.menu>
                @endif
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

@if($canManageLazada)
{{-- Add one at a time. Same endpoints as before: a GET to search-products?q=
     for the results, and one POST to addProducts carrying `ids[]`. The results
     are built with createElement and textContent only. --}}
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-search-url="{{ route('ext.lazada.product-groups.searchProducts') }}"
         data-form="lazada-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the group's filter does not pick up. Type at least two characters.</p>

    <form id="lazada-add-products" method="POST" action="{{ route('ext.lazada.product-groups.addProducts', $group->id) }}">
        @csrf
        <input type="hidden" name="_return" value="{{ $returnTo }}">
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalogue to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="lazada-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

{{-- Work-in-progress overlay. A push to Lazada is a multi-second round trip
     and the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class the confirm dialog and the orders pages use. --}}
<div id="lazada-group-progress" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="cc-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" data-progress-title>Talking to Lazada</div>
                <div class="cc-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>{{-- /.cc-page --}}
@endsection
