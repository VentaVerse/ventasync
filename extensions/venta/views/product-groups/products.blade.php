@extends('layouts.channel')
@section('title', $group->name . ' Products, ' . $setting->store_name)
@section('breadcrumb', $group->name . ' Products')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     Venta is multi-store. Every self-link, every form action and the
     product-search endpoint carry the {store} segment this page arrived on,
     and every POST carries the `_return` field VentaProductGroupController::
     productsRedirect() reads to come back to the same filtered page. That
     field used to be injected by an inline script at load; it is a real
     hidden input now, which is the same value ($_SERVER request URI) without
     depending on JavaScript having run. --}}
@section('content')
@php
    // Read tier on this route is `view_venta|manage_venta`; every push, sync,
    // link, unlink and remove below is `manage_venta`. Replaces a hardcoded
    // `true`, which offered a read-only operator a dozen controls the routes
    // would refuse.
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';
    $returnTo = request()->getRequestUri();

    $q = (string) ($q ?? '');
    $erpStatus = (string) ($erpStatus ?? 'all');
    $syncStatus = (string) ($syncStatus ?? 'all');
    $hasFilters = $q !== '' || $erpStatus !== 'all' || $syncStatus !== 'all';

    $listUrl = route('ext.venta.product-groups.products', [$setting->id, $group->id]);
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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this group on {{ $storeName }}
        </p>
    </div>
    {{-- Both of these ask about the whole group, which is why they sit in the
         header and not in the bulk bar: the bulk bar only exists once rows are
         ticked, and "is anything out of step?" is a question you ask before
         you know which rows to tick.

         Check posts, because it writes. Unlinked is a GET, because it only
         reads - it is the only way to see products Venta has that the ERP has
         never heard of, which no per-product action can reach. --}}
    <div class="cc-head-actions">
        @if($canManageVenta)
            <form method="POST" action="{{ route('ext.venta.product-groups.check', [$setting->id, $group->id]) }}"
                  data-confirm="Check every product in this group against {{ $storeName }}? Nothing is sent or changed on {{ $storeName }}; the ERP updates its own records to match what it finds.">
                @csrf
                <input type="hidden" name="_return" value="{{ $returnTo }}">
                <x-ui.button type="submit" variant="secondary">Check against {{ $storeName }}</x-ui.button>
            </form>
        @endif
        <x-ui.button variant="secondary" :href="route('ext.venta.product-groups.orphans', [$setting->id, $group->id])">
            Unlinked on {{ $storeName }}
        </x-ui.button>
        @if($canManageVenta)
            <x-ui.button :href="route('ext.venta.product-groups.edit', [$setting->id, $group->id])">Edit group</x-ui.button>
        @endif
    </div>
</div>

{{-- One filter row. The old page put the same three controls in a card with a
     Filter button; the two selects submit on change (data-autosubmit,
     resources/js/list-filters.js) and Clear is a link, because it is
     navigation and not an action. Every param name is unchanged. --}}
<form method="GET" action="{{ $listUrl }}" class="x-filters">
    <x-ui.input type="search" name="q" class="x-filters__search"
                value="{{ $q }}" placeholder="Name, model or SKU" aria-label="Search products in this group" />

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
            <option value="error" @selected($syncStatus === 'error')>Last push failed</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $listUrl }}">Clear</a>
    @endif
</form>

@if($canManageVenta)
{{-- Bulk bar. It exists only once at least one row is ticked, which is why
     the old page's standing accent-tinted strip of five buttons is gone. Each
     action is still its own form posting `ids[]` to its own route; the module
     injects the ticked ids into whichever form is submitted. Remove from group
     keeps the transparent danger treatment, not a solid red fill. --}}
{{-- role="status" so the bar arriving is heard, not only seen. aria-live is
     on the COUNT rather than the whole bar: the buttons do not change, and
     re-reading their labels on every tick would be worse than silence. --}}
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        <form method="POST" action="{{ route('ext.venta.product-groups.push', [$setting->id, $group->id]) }}" data-bulk-form
              data-bulk-confirm="Push the selected products to {{ $storeName }}? Anything already pushed is updated in place.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push to Venta</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.venta.product-groups.push-prices', [$setting->id, $group->id]) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.venta.product-groups.push-stock', [$setting->id, $group->id]) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        {{-- Was "Match Venta IDs", which only filled in blanks: it skipped any
             product that already had a link, so the case that matters - a link
             that has gone stale - was the one thing it would not look at. It
             now asks about every selected product and says what it found. --}}
        <form method="POST" action="{{ route('ext.venta.product-groups.check', [$setting->id, $group->id]) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Check against {{ $storeName }}</x-ui.button>
        </form>
        {{-- Remove from group first, then Delete from Venta LAST and alone in
             the danger fill, matching Shopee, Lazada and TikTok.

             Venta was the only one of the four that could not delete in bulk,
             so its last slot held Remove from group in red - the same
             position and the same colour that means "delete from the
             marketplace" on the other three. An operator moving between
             channels reads position before words. --}}
        <form method="POST" action="{{ route('ext.venta.product-groups.mass-remove', [$setting->id, $group->id]) }}" data-bulk-form
              data-bulk-confirm="Take the selected products out of this group? Nothing is deleted from the catalog, and nothing already live on {{ $storeName }} is touched.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Remove from group</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.venta.product-groups.bulk-delete-from-venta', [$setting->id, $group->id]) }}" data-bulk-form
              data-bulk-confirm="Delete the selected products from {{ $storeName }}? They are removed at the storefront and this cannot be undone.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" variant="danger" size="sm">Delete from Venta</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalog and push filters.'
                    : 'Edit the group to pick products from the catalog, or add them one at a time below.'">
        @if($canManageVenta && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.venta.product-groups.edit', [$setting->id, $group->id])">Edit group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
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
            <th scope="col" class="cc-col-chanid">Venta ID</th>
            <th scope="col" class="cc-col-stat">Catalog</th>
            <th scope="col" class="cc-col-sync">Push</th>
            <th scope="col" class="cc-col-err">Last push</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : null;

            $syncState = $p->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked';
            $hasVentaId = !empty($p->venta_product_id) && !$isUnlinked;
            $failed = in_array($syncState, ['error', 'failed'], true);
            $hasError = $failed && ($p->push_error ?? null);

            // Parsed rather than cast: this comes off a query builder select,
            // not an Eloquent model, so it arrives as a string and has no
            // diffForHumans on it.
            $confirmedAt = ($p->last_confirmed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($p->last_confirmed_at)
                : null;


            $optRows = isset($optionRowsByProductId) ? $optionRowsByProductId->get($p->product_id) : null;
            $variationCount = $optRows ? count($optRows) : 0;

            $pName = html_entity_decode((string) ($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pSku = (string) ($p->sku ?: ($p->model ?: ''));
            $isManual = in_array((int) $p->product_id, $manualIds, true);

            // Built here rather than as a run of adjacent @if directives in the
            // markup: Blade's directive pattern needs a non-word character
            // before an @, so `@endif@if(` leaves the second directive as
            // literal text in the page. Caught in the browser.
            $metaParts = [$pSku !== '' ? $pSku : 'No SKU'];
            if (!empty($p->manufacturer_name)) {
                $metaParts[] = (string) $p->manufacturer_name;
            }
            $metaParts[] = $isManual ? 'added by hand' : 'matched by the group filter';
            $meta = implode(' / ', $metaParts);
        @endphp
        <tr>
            @if($canManageVenta)
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
                            <img src="{{ $thumbSrc }}" alt="" loading="lazy" decoding="async" data-thumb>
                        @endif
                    </div>
                    <div class="co-item__body">
                        <a class="x-row-link co-item__name" href="{{ route('products.edit', $p->product_id) }}">{{ $pName ?: 'Unnamed product' }}</a>
                        {{-- SKU and manufacturer sit here rather than in
                             columns of their own: thirteen fixed columns left
                             the product name unreadable at 1440, which is the
                             same measurement Lazada's rebuilt Listings table
                             recorded. --}}
                        <span class="co-item__meta">{{ $meta }}</span>

                        @if($variationCount > 0)
                            <div class="cc-vars">
                                @foreach($optRows as $or)
                                    @php
                                        $ovn = (string) ($or->option_value_name ?? '');
                                        $osku = (string) ($or->sku ?? '');
                                        $oqty = (int) ($or->quantity ?? 0);
                                        $oprice = (float) ($or->absolute_price ?? 0);
                                        $oimg = trim((string) ($or->option_image ?? ''));
                                        $osrc = $oimg !== '' ? asset('storage/' . ltrim($oimg, '/')) : '';
                                    @endphp
                                    <div class="cc-var">
                                        <span class="cc-var__thumb">@if($osrc)<img src="{{ $osrc }}" alt="" loading="lazy" decoding="async">@endif</span>
                                        <span class="cc-var__name">{{ $ovn !== '' ? $ovn : 'Unnamed variation' }}</span>
                                        <span class="cc-var__sku">{{ $osku }}</span>
                                        <span class="cc-var__qty">{{ $oqty }} in stock</span>
                                        {{-- Never a hardcoded symbol: App\Support\Money reads the
                                             default currency off the currencies table, the same
                                             resolution <x-money> makes. The component itself is
                                             not used here because it renders a 600-weight primary
                                             line, and this is an 11px caption inside a row. --}}
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

            <td class="cc-col-chanid" data-label="Venta ID">
                @if($hasVentaId)
                    <span class="x-num">{{ $p->venta_product_id }}</span>
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
                {{-- FAILED IS TESTED BEFORE PUSHED, and the order is the whole
                     point. This used to ask "does it have a Venta id?" first,
                     so a row carrying an id read "Pushed" no matter what the
                     status said, and the Failed badge could never render for
                     any product that had one - which is nearly every product
                     that can fail. A row states its most recent outcome, not
                     the best outcome it has ever had. --}}
                @if($isUnlinked)
                    <span class="x-cell-muted">Unlinked</span>
                @elseif($failed)
                    <x-ui.badge tone="danger">Failed</x-ui.badge>
                @elseif($hasVentaId)
                    <x-ui.badge tone="success">Pushed</x-ui.badge>
                @else
                    <span class="x-cell-muted">Not yet</span>
                @endif

                {{-- The badge above states what the ERP BELIEVES. This line says
                     how that belief was arrived at, and the difference is the
                     whole reason this page could mislead: a green Pushed badge
                     was written at push time and then never revisited, so a
                     product deleted in Venta's admin kept it indefinitely.

                     "Not checked" is not a warning. It is the ordinary state of
                     a product nobody has asked about since it was pushed, and
                     it is accurate far more often than not. It is here so the
                     operator can tell a fact from a memory at a glance, and so
                     that Check against the store has a visible effect. --}}
                @if(! $isUnlinked)
                    @if($confirmedAt)
                        <span class="cc-checked" title="Last asked {{ $confirmedAt->toDayDateTimeString() }}">Checked {{ $confirmedAt->diffForHumans() }}</span>
                    @elseif($hasVentaId)
                        <span class="cc-checked cc-checked--never">Not checked since the push</span>
                    @endif
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
                        $reason = (string) $p->push_error;
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
                    @if($p->last_pushed_at)
                        <span class="cc-when">{{ \Carbon\Carbon::parse($p->last_pushed_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasVentaId && $p->last_pushed_at)
                    <span class="cc-when">{{ \Carbon\Carbon::parse($p->last_pushed_at)->diffForHumans() }}</span>
                @else
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageVenta)
                {{-- The old row carried a hand-rolled dropdown built from
                     inline styles and its own outside-click handler. Same
                     branching, same routes, same confirm messages; the popup is
                     <x-ui.menu> now, which Alpine drives. --}}
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    {{-- ONE primary action, never gated.

                         It replaced Push, Update on Venta, Match Venta ID,
                         Link to Venta again and Push as new - five buttons
                         that existed only to describe which local state the
                         row happened to be in, shown in mutually exclusive
                         sets. A product deleted in Venta's admin landed in the
                         one state that offered none of the useful ones, and
                         the two it did offer never contacted Venta at all.

                         This asks Venta what is true, then creates, updates,
                         re-links or recreates accordingly, and says which it
                         did. There is no state of this page in which the
                         right action is missing. --}}
                    <form method="POST" action="{{ route('ext.venta.product-groups.push', [$setting->id, $group->id]) }}" data-slow-action>
                        @csrf
                        <input type="hidden" name="_return" value="{{ $returnTo }}">
                        <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                        <button type="submit" class="x-menu__item x-menu__item--primary">Send to {{ $storeName }}</button>
                    </form>

                    <div class="x-menu__sep" role="separator"></div>

                    {{-- Only meaningful once something is linked. Sending
                         establishes that, so these follow it rather than
                         competing with it. --}}
                    @if($hasVentaId)
                        <form method="POST" action="{{ route('ext.venta.product-groups.push-stock', [$setting->id, $group->id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push stock</button>
                        </form>
                        <form method="POST" action="{{ route('ext.venta.product-groups.push-prices', [$setting->id, $group->id]) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push price</button>
                        </form>
                        <div class="x-menu__sep" role="separator"></div>
                    @endif

                    <a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalog</a>

                    @if($isManual)
                        <form method="POST" action="{{ route('ext.venta.product-groups.removeProduct', [$setting->id, $group->id, $p->product_id]) }}">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item"
                                    data-confirm="Take {{ $pName ?: 'this product' }} out of this group? Nothing is deleted from the catalog, and nothing on {{ $storeName }} is touched."
                                    data-confirm-tone="primary">Remove from group</button>
                        </form>
                    @endif

                    @if($hasVentaId)
                        <div class="x-menu__sep" role="separator"></div>
                        <form method="POST" action="{{ route('ext.venta.product-groups.unlink', [$setting->id, $group->id, $p->product_id]) }}">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item"
                                    data-confirm="Forget the connection between this product and {{ $storeName }}? Nothing is deleted there. Sending it again will re-match or create it."
                                    data-confirm-tone="primary">Unlink</button>
                        </form>
                        <form method="POST" action="{{ route('ext.venta.product-groups.deleteFromVenta', [$setting->id, $group->id, $p->product_id]) }}">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger"
                                    data-confirm="Delete {{ $pName ?: 'this product' }} from {{ $storeName }}? It is removed at the storefront and this cannot be undone.">Delete from {{ $storeName }}</button>
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

@if($canManageVenta)
{{-- Add one at a time. Same endpoints as before: a GET to search-products?q=
     for the results, and one POST to addProducts carrying `ids[]`. The results
     are built with createElement and textContent only. --}}
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-search-url="{{ route('ext.venta.product-groups.searchProducts', $setting->id) }}"
         data-form="venta-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the group's filter does not pick up. Type at least two characters.</p>

    <form id="venta-add-products" method="POST" action="{{ route('ext.venta.product-groups.addProducts', [$setting->id, $group->id]) }}">
        @csrf
        <input type="hidden" name="_return" value="{{ $returnTo }}">
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalog to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="venta-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

{{-- Work-in-progress overlay. A push to Venta is a multi-second round trip and
     the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class the confirm dialog and the orders pages use. --}}
<div id="venta-group-progress" class="modal-backdrop">
    {{-- A dialog, and one that speaks. This overlay covers the page for
         several seconds while a marketplace call is in flight and carried no
         role, no name and no live region, so the only signal that anything
         was happening was the spinner - which is to say none at all for
         anyone not looking at it. --}}
    <div class="modal co-modal co-modal--sm" role="dialog" aria-modal="true"
         aria-labelledby="venta-group-progress-title" aria-describedby="venta-group-progress-note">
        <div class="cc-progress" role="status" aria-live="polite">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="cc-progress__title" id="venta-group-progress-title" data-progress-title>Talking to Venta</div>
                <div class="cc-progress__note" id="venta-group-progress-note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

</div>{{-- /.cc-page --}}
@endsection
