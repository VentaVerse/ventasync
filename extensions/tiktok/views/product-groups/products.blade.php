@extends('layouts.channel')
@section('title', $group->name . ' Products, TikTok Shop')
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
       - Delete from TikTok Shop was a solid red button standing on the page. It is
         in the bulk bar, at transparent danger weight, behind a confirmation;
       - the `_return` field used to be injected into every POST form by a
         script at load. It is a real hidden input now, the same value, without
         depending on JavaScript having run;
       - `$canManageTiktok` was hardcoded true, so a view_tiktok operator was
         shown a dozen controls the routes would refuse.

     The wire is unchanged: the same `ids[]`, the same `_return`, the same
     routes, the same GET filter parameters, the same search endpoint and the
     same two-character minimum. --}}
@section('content')
@php
    // Read tier on this route is `view_tiktok|manage_tiktok`; every push,
    // sync, link, unlink and remove below is `manage_tiktok`.
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/product_group') ?? false;

    $returnTo = request()->getRequestUri();

    $q = (string) ($q ?? '');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $hasFilters = $q !== '' || $erpStatus !== 'all' || $syncStatus !== 'all';

    $listUrl = route('ext.tiktok.product-groups.products', $group->id);
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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this group on TikTok Shop
        </p>
    </div>
    @if($canManageTiktok)
        <x-ui.button :href="route('ext.tiktok.product-groups.edit', $group->id)">Edit group</x-ui.button>
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

@if($canManageTiktok)
{{-- Bulk bar. It exists only once at least one row is ticked. Each action is
     still its own form posting `ids[]` to its own route; the module injects
     the ticked ids into whichever form is submitted.

     Delete from TikTok Shop carries data-bulk-confirm rather than data-confirm.
     The old page carried both a data-confirm attribute AND a confirmModal()
     call in its own submit handler, so the operator could be asked twice for
     the same deletion. One mechanism, one question. --}}
{{-- role="status" so the bar arriving is heard, not only seen. aria-live is
     on the COUNT rather than the whole bar: the buttons do not change, and
     re-reading their labels on every tick would be worse than silence. --}}
<div class="cc-bulkbar" x-show="selected.length  role="status"> 0" x-cloak data-bulkbar>
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.tiktok.product-groups.push', $group->id) }}" data-bulk-form
              data-bulk-confirm="Push the selected products to TikTok Shop? Anything already pushed is skipped.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push to TikTok Shop</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushPrices', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushStock', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.product-groups.massRemove', $group->id) }}" data-bulk-form
              data-bulk-confirm="Take the selected products out of this group? Nothing is deleted from the catalogue, and nothing already live on TikTok Shop is touched.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Remove from group</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.tiktok.product-groups.deleteFromTikTok', $group->id) }}" data-bulk-form
              data-bulk-confirm="Delete the selected products from TikTok Shop? They are removed at the marketplace and this cannot be undone.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" variant="danger" size="sm">Delete from TikTok Shop</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalogue and push filters.'
                    : 'Edit the group to pick products from the catalogue, or add them one at a time below.'">
        @if($canManageTiktok && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.tiktok.product-groups.edit', $group->id)">Edit group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            @if($canManageTiktok)
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
            <th scope="col" class="cc-col-chanid">TikTok ID</th>
            <th scope="col" class="cc-col-stat">Catalogue</th>
            <th scope="col" class="cc-col-sync">Push</th>
            <th scope="col" class="cc-col-err">Last push</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pivot = $pivotMap->get($p->product_id);

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : null;

            $syncState = $pivot->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked';
            $hasTikTokId = $pivot && $pivot->tiktok_product_id && !$isUnlinked;
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
            @if($canManageTiktok)
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

            <td class="cc-col-chanid" data-label="TikTok ID">
                @if($hasTikTokId)
                    <span class="x-num">{{ $pivot->tiktok_product_id }}</span>
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
                {{-- FAILED IS TESTED BEFORE PUSHED, and the order is the whole
                     point. This used to ask "does it have a TikTok id?" first,
                     so a row carrying an id read "Pushed" no matter what the
                     status said, and the Failed badge could never render for
                     any product that had one - which is nearly every product
                     that can fail. A row states its most recent outcome, not
                     the best outcome it has ever had. --}}
                @if($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @elseif($failed)
                    <x-ui.badge tone="danger">Failed</x-ui.badge>
                @elseif($hasTikTokId)
                    <x-ui.badge tone="success">Pushed</x-ui.badge>
                @else
                    <span class="x-cell-muted">Not yet</span>
                @endif
            </td>

            <td class="cc-col-err" data-label="Last push">
                {{-- A failure with no saved message still says so. Silence in a
                     column that only ever fills on failure reads as nothing
                     wrong, and rendered "Never" - the strongest possible
                     statement that nothing has ever gone wrong. --}}
                @if($failed && ! $hasError)
                    <span class="cc-err">Push failed. No reason was recorded.</span>
                @elseif($hasError)
                    @php
                        $reason = (string) $pivot->push_error;
                        // 120 shown against a column stored at 480: the rest
                        // was unreachable from the interface entirely.
                        $clipped = \Illuminate\Support\Str::limit($reason, 120);
                    @endphp

                    @if($clipped === $reason)
                        <span class="cc-err">{{ $reason }}</span>
                    @else
                        {{-- <details>, not a tooltip: a title attribute waits a
                             second, never appears on a phone, and cannot be
                             reached from the keyboard. --}}
                        <details class="cc-reason">
                            <summary class="cc-reason__summary">
                                <span class="cc-err cc-reason__clip">{{ $clipped }}</span>
                                <span class="cc-reason__toggle">
                                    <x-ui.icon name="chevron-down" size="12" class="cc-reason__chevron" />
                                    <span class="cc-reason__label">Full reason</span>
                                </span>
                            </summary>
                            <p class="cc-err cc-reason__full">{{ $reason }}</p>
                        </details>
                    @endif
                    @if($pivot->last_pushed_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasTikTokId && ($pivot->last_pushed_at ?? null))
                    <span class="cc-when">{{ \Carbon\Carbon::parse($pivot->last_pushed_at)->diffForHumans() }}</span>
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageTiktok)
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalogue</a>

                    {{-- && !$isUnlinked, matching Venta. Without it an unlinked
                         row offered Push and "Link again" side by side, which are
                         opposite answers to the same question. --}}
                    @if(!$hasTikTokId && !$isUnlinked)
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.syncId', [$group->id, $p->product_id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item">Match TikTok ID</button>
                        </form>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.push', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push to TikTok Shop</button>
                        </form>
                    @endif

                    @if($hasTikTokId)
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.updateProduct', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Update on TikTok Shop</button>
                        </form>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushStock', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push stock</button>
                        </form>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.pushPrices', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push price</button>
                        </form>
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.unlinkProduct', [$group->id, $p->product_id]) }}" data-slow-action
                              data-confirm="Unlink {{ $pName ?: 'this product' }} from TikTok Shop? The product stays in the group but sync will stop.">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from TikTok Shop</button>
                        </form>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.deleteFromTikTok', $group->id) }}" data-slow-action
                              data-confirm="Delete {{ $pName ?: 'this product' }} from TikTok Shop? It is removed at the marketplace and this cannot be undone.">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Delete from TikTok Shop</button>
                        </form>
                    @endif

                    @if($isUnlinked)
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.linkProduct', [$group->id, $p->product_id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item">Link to TikTok Shop again</button>
                        </form>
                    @endif

                    @if($isManual)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.tiktok.product-groups.removeProduct', [$group->id, $p->product_id]) }}"
                              data-confirm="Take {{ $pName ?: 'this product' }} out of the group? Nothing is deleted from the catalogue, and nothing already live on TikTok Shop is touched.">
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

<x-ui.pager :paginator="$products" />
@endif

@if($canManageTiktok)
{{-- Add one at a time. Same endpoints as before: a GET to search-products?q=
     for the results, and one POST to addProducts carrying `ids[]`. The results
     are built with createElement and textContent only. --}}
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-search-url="{{ route('ext.tiktok.product-groups.searchProducts') }}"
         data-form="tiktok-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the group's filter does not pick up. Type at least two characters.</p>

    <form id="tiktok-add-products" method="POST" action="{{ route('ext.tiktok.product-groups.addProducts', $group->id) }}">
        @csrf
        <input type="hidden" name="_return" value="{{ $returnTo }}">
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalogue to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="tiktok-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

{{-- Work-in-progress overlay. A push to TikTok Shop is a multi-second round trip
     and the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class the confirm dialog and the orders pages use. --}}
<div id="tiktok-group-progress" class="modal-backdrop">
    {{-- A dialog, and one that speaks. This overlay covers the page for
         several seconds while a marketplace call is in flight and carried no
         role, no name and no live region, so the only signal that anything
         was happening was the spinner - which is to say none at all for
         anyone not looking at it. --}}
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="tiktok-group-progress-title" aria-describedby="tiktok-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="tiktok-group-progress-title" data-progress-title>Talking to TikTok Shop</div>
                <div class="cc-progress__note" id="tiktok-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>{{-- /.cc-page --}}
@endsection
