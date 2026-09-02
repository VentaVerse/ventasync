@extends('layouts.channel')
@section('title', $group->name . ' Products, Shopee')
@section('breadcrumb', $group->name . ' Products')

{{-- Round F Task 1. Rebuilt on the shared cc- list anatomy
     (pages/channel-catalog.css), the same one Venta's and OpenCart's identical
     pages already use, with the behaviour moved out of an inline <script> into
     resources/js/pages/group-products.js.

     What changed and why, beyond the drawing:

       - the per-row dropdown of up to seven controls was a hand-rolled popup
         built from inline styles with its own outside-click handler. It is
         <x-ui.menu> now, which Alpine drives, and the row itself is clickable
         through to the catalog product;
       - the standing accent-tinted strip of five buttons is a bulk bar that
         does not exist until at least one row is ticked;
       - Delete from Shopee was a solid red button standing on the page. It is
         in the bulk bar, at transparent danger weight, behind a confirmation;
       - the `_return` field used to be injected into every POST form by a
         script at load. It is a real hidden input now, the same value, without
         depending on JavaScript having run;
       - `$canManageShopee` was hardcoded true, so a view_shopee operator was
         shown a dozen controls the routes would refuse.

     The wire is unchanged: the same `ids[]`, the same `_return`, the same
     routes, the same GET filter parameters, the same search endpoint and the
     same two-character minimum. --}}
@section('content')
@php
    // Read tier on this route is `view_shopee|manage_shopee`; every push,
    // sync, link, unlink and remove below is `manage_shopee`.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;
    // Unlist/Relist ride the listing routes, which carry the product
    // permission - shown only to operators the route would let through.
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
            {{ number_format($products->total()) }} {{ $products->total() === 1 ? 'product' : 'products' }} in this group on Shopee
        </p>
    </div>
    @if($canManageShopee)
        {{-- Both ask about the whole group, which is why they sit here and not
             in the bulk bar: the bulk bar only appears once rows are ticked,
             and "is anything out of step?" is what you ask before you know
             which rows to tick. Check posts because it writes; Unlinked is a
             GET because it only reads. --}}
        <div class="cc-head-actions">
            @if($canManageShopee)
                <form method="POST" action="{{ route('ext.shopee.product-groups.check', $group->id) }}"
                      data-confirm="Check every product in this group against Shopee? Nothing is sent or changed on Shopee; the ERP updates its own records to match what it finds." data-confirm-tone="primary" data-confirm-verb="Check against Shopee">
                    @csrf
                    <input type="hidden" name="_return" value="{{ $returnTo }}">
                    <x-ui.button type="submit" variant="secondary">Check against Shopee</x-ui.button>
                </form>
            @endif
            <x-ui.button variant="secondary" :href="route('ext.shopee.product-groups.orphans', $group->id)">Unlinked on Shopee</x-ui.button>
            <x-ui.button :href="route('ext.shopee.product-groups.edit', $group->id)">Edit group</x-ui.button>
        </div>
    @endif
</div>

{{-- One filter row. The two selects submit on change (data-autosubmit,
     resources/js/list-filters.js) and Clear is a link, because it is
     navigation and not an action. Every parameter name is unchanged. --}}
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
            <option value="all" @selected($syncStatus === 'all')>Listed or not</option>
            <option value="pushed" @selected($syncStatus === 'pushed')>Listed on Shopee</option>
            <option value="pending" @selected($syncStatus === 'pending')>Not listed</option>
            <option value="error" @selected($syncStatus === 'error')>Last push from this group failed</option>
            <option value="unlinked" @selected($syncStatus === 'unlinked')>Unlinked</option>
        </select>
        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
    </div>

    <x-ui.button type="submit" variant="secondary">Search</x-ui.button>

    @if($hasFilters)
        <a class="x-filters__reset" href="{{ $listUrl }}">Clear</a>
    @endif
</form>

{{-- Failures outlive the banner that announced them.

     A push summary named up to five failures and then vanished on the next
     click, so an operator who navigated away had to remember which of twelve
     had failed. The count is taken across the whole group rather than the
     current page or filter, so it does not quietly shrink as you narrow the
     list, and it is a link to exactly the rows it counts.

     Hidden while that filter is already applied, because a pointer to where
     you are standing is noise. --}}
@if(($failedCount ?? 0) > 0 && $syncStatus !== 'error')
    <p class="cc-failnote">
        <x-ui.icon name="alert-triangle" size="13" class="cc-failnote__icon" />
        <span><span class="x-num">{{ number_format($failedCount) }}</span>
            {{ \Illuminate\Support\Str::plural('product', $failedCount) }} in this group
            {{ $failedCount === 1 ? 'failed its' : 'failed their' }} last push from this group.</span>
        <a class="cc-failnote__link" href="{{ request()->fullUrlWithQuery(['sync_status' => 'error', 'page' => null]) }}">Show them</a>
    </p>
@endif

@if($canManageShopee)
{{-- Bulk bar. It exists only once at least one row is ticked. Each action is
     still its own form posting `ids[]` to its own route; the module injects
     the ticked ids into whichever form is submitted.

     Delete from Shopee carries data-bulk-confirm rather than data-confirm.
     The old page carried both a data-confirm attribute AND a confirmModal()
     call in its own submit handler, so the operator could be asked twice for
     the same deletion. One mechanism, one question. --}}
{{-- role="status" so the bar arriving is heard, not only seen. aria-live is
     on the COUNT rather than the whole bar: the buttons do not change, and
     re-reading their labels on every tick would be worse than silence. --}}
<div class="cc-bulkbar" x-show="selected.length > 0" x-cloak data-bulkbar role="status">
    <span class="cc-bulkbar__count" aria-live="polite"><span class="x-num" x-text="selected.length"></span> selected</span>
    <div class="cc-bulkbar__actions">
        {{-- Send, not Push. The old wording promised "anything already pushed
             is skipped", which was true and was the defect: a product whose
             listing had been deleted on Shopee kept its link row, so bulk push
             skipped it forever and the operator had no way to notice. Send
             checks each product against Shopee first and then creates or
             updates it. --}}
        <form method="POST" action="{{ route('ext.shopee.product-groups.send', $group->id) }}" data-bulk-form
              data-bulk-confirm="Send the selected products to Shopee? Each one is checked against Shopee first, then created or updated to match the catalog.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Send to Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.product-groups.pushPrices', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push prices</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.product-groups.pushStock', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Push stock</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.product-groups.check', $group->id) }}" data-bulk-form>
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Check against Shopee</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.product-groups.massRemove', $group->id) }}" data-bulk-form
              data-bulk-confirm="Take the selected products out of this group? Nothing is deleted from the catalog, and nothing already live on Shopee is touched.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" size="sm">Remove from group</x-ui.button>
        </form>
        <form method="POST" action="{{ route('ext.shopee.product-groups.deleteFromShopee', $group->id) }}" data-bulk-form
              data-bulk-confirm="Delete the selected products from Shopee? They are removed at the marketplace and this cannot be undone.">
            @csrf
            <input type="hidden" name="_return" value="{{ $returnTo }}">
            <x-ui.button type="submit" variant="danger" size="sm">Delete from Shopee</x-ui.button>
        </form>
    </div>
</div>
@endif

@if($products->count() === 0)
    <x-ui.empty title="{{ $hasFilters ? 'Nothing matches those filters' : 'No products in this group' }}"
                :description="$hasFilters
                    ? 'Try clearing the search, or widening the catalog and push filters.'
                    : 'Edit the group to pick products from the catalog, or add them one at a time below.'">
        @if($canManageShopee && !$hasFilters)
        <x-slot:action>
            <x-ui.button variant="primary" :href="route('ext.shopee.product-groups.edit', $group->id)">Edit group</x-ui.button>
        </x-slot:action>
        @endif
    </x-ui.empty>
@else
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
            <th scope="col" class="cc-col-err">Last push</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($products as $p)
        @php
            $pivot = $pivotMap->get($p->product_id);

            $img = trim((string) ($p->image ?? ''));
            $thumbSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : null;

            /* PRODUCT truth vs GROUP memory (stage 1 of the one-truth
               repointing, 2026-08-24). Whether this product is on Shopee,
               under which item id, and what last went up are facts that
               exist once per product - read from the links and the listing
               record, the same rows the Listings page reads, so nine group
               pages can no longer tell nine stories about one product. The
               pivot keeps only what is genuinely this group's own: its
               last push ATTEMPT and why it failed. */
            $rowLink = ($linksByProductId->get((int) $p->product_id) ?? collect())->first();
            $rowListing = $listingsByProductId->get((int) $p->product_id);
            $listed = $rowLink !== null;

            $syncState = $pivot->sync_status ?? 'pending';
            $isUnlinked = $syncState === 'unlinked' && ! $listed;
            $attemptFailed = $syncState === 'error';
            $attemptError = $attemptFailed ? ($pivot->push_error ?? null) : null;
            $attemptAt = ($pivot->last_pushed_at ?? null)
                ? \Illuminate\Support\Carbon::parse($pivot->last_pushed_at)
                : null;

            $provenanceAt = $rowListing?->last_pushed_at;
            $provenanceSource = (string) ($rowListing->last_push_source ?? '');
            $provenanceLabel = match (true) {
                $provenanceSource === 'form' => 'from the push form',
                $provenanceSource === 'listing' => 'from its listing',
                str_starts_with($provenanceSource, 'group:') => 'from the ' . substr($provenanceSource, 6) . ' group',
                default => '',
            };

            /* The checked-at stamp is the PRODUCT's (stage 2, 2026-08-25):
               it reads the listing record, so a check run from any page
               shows on all of them. The pivot's per-group copy stops being
               written and awaits the prune. */
            $confirmedAt = $rowListing?->last_checked_at;

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
            @if($canManageShopee)
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

            <td class="cc-col-chanid" data-label="Shopee ID">
                @if($listed)
                    @php $liveUrl = \Extensions\shopee\Models\ShopeeSetting::publicListingUrl($rowLink->shopee_item_id); @endphp
                    @if($liveUrl)
                        <a class="x-num" href="{{ $liveUrl }}" target="_blank" rel="noopener">{{ $rowLink->shopee_item_id }}</a>
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
                {{-- The PRODUCT's state, the same words the Listings page
                     uses for the same fact. A failed push from THIS group no
                     longer masquerades as the product's status - the product
                     the owner caught read "Failed" here while the Listings page
                     said Live, because this column used to show this group's
                     memory instead of the product's record. The attempt and
                     its reason live in the Last push column, scoped to this
                     group in words. --}}
                @if($listed)
                    {{-- The engine's word, not "Listed" (Phase B, #184): a
                         banned product used to read Listed here and
                         Violation on the index. One vocabulary now. --}}
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

                {{-- "Not checked" is not a warning and is not styled as one.
                     It is the ordinary state of a product nobody has asked
                     about since it was pushed. It is here so a fact can be
                     told from a memory at a glance, and so Check has a
                     visible effect. (The stamp is still this group's own -
                     the write moves product-level in stage 2.) --}}
                @if($listed)
                    @if($confirmedAt)
                        <span class="cc-checked" title="Last asked {{ $confirmedAt->toDayDateTimeString() }}">Checked {{ $confirmedAt->diffForHumans() }}</span>
                    @else
                        <span class="cc-checked cc-checked--never">Not checked since the push</span>
                    @endif
                @endif
            </td>

            <td class="cc-col-err" data-label="Last push">
                {{-- Two different facts share this column, each named:
                     what actually went up (the listing record, shared with
                     every page) and what THIS GROUP last tried (its own
                     attempt history). The owner's GP-180 showed why both must
                     speak: this group's push failed on couriers, then the
                     product went up through its listing - the truthful row
                     says both. --}}
                @if($attemptFailed)
                    @php
                        $reason = trim((string) ($attemptError ?? ''));
                        $reason = $reason !== '' ? $reason : 'No reason was recorded.';
                        // 120 shown against a column stored at 480: an
                        // untitled truncation left up to 360 characters of
                        // what Shopee actually said unreachable.
                        $clipped = \Illuminate\Support\Str::limit($reason, 120);
                    @endphp
                    <span class="cc-err">This group's last push{{ $attemptAt ? ' ' . $attemptAt->diffForHumans() : '' }} failed:</span>
                    @if($clipped === $reason)
                        <span class="cc-err">{{ $reason }}</span>
                    @else
                        {{-- <details>, not a tooltip: a title attribute waits
                             a second, never appears on a phone, and cannot be
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
                @endif

                @if($provenanceAt)
                    <span class="cc-when">Pushed {{ $provenanceAt->diffForHumans() }}{{ $provenanceLabel !== '' ? ' ' . $provenanceLabel : '' }}</span>
                    {{-- "Core has changed" (#100): the catalog moved after
                         this went up, so the live listing is behind what a
                         push would send now. --}}
                    @php
                        $coreMovedAt = ($p->date_modified ?? null)
                            ? \Illuminate\Support\Carbon::parse($p->date_modified) : null;
                    @endphp
                    @if($coreMovedAt && $coreMovedAt->gt($provenanceAt))
                        <span class="cc-checked cc-checked--stale">Catalog changed since this push</span>
                    @endif
                @elseif(! $attemptFailed)
                    <span class="x-cell-muted">Never</span>
                @endif
            </td>

            <td class="x-td-actions">
                @if($canManageShopee)
                <x-ui.menu label="Product {{ $p->product_id }} actions">
                    {{-- SEND IS ALWAYS PRESENT AND NEVER GATED, and that is the
                         whole change. This menu used to offer Match Shopee ID,
                         Push to Shopee, Update on Shopee and Link to Shopee
                         again - four buttons that were one question asked four
                         ways, each visible only when the local link row was in
                         a particular shape.

                         That shape was often wrong, and when it was, the row
                         offered nothing that could work: delete an item in
                         Seller Centre and Push stayed hidden because a link
                         existed, while Update could only answer item_not_found.
                         Unlink looked like the escape and led straight back to
                         "Link again", which succeeded against a listing that
                         was not there.

                         Send asks Shopee first and then creates, updates or
                         re-links according to the answer. Status is now
                         informational; it decides nothing. --}}
                    <form method="POST" action="{{ route('ext.shopee.product-groups.send', $group->id) }}" data-slow-action>
                        @csrf
                        <input type="hidden" name="_return" value="{{ $returnTo }}">
                        <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                        <button type="submit" class="x-menu__item">Send to Shopee</button>
                    </form>

                    @if($listed)
                        <form method="POST" action="{{ route('ext.shopee.product-groups.pushStock', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push stock</button>
                        </form>
                        <form method="POST" action="{{ route('ext.shopee.product-groups.pushPrices', $group->id) }}" data-slow-action>
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item">Push price</button>
                        </form>
                        {{-- The marketplace's own on/off, from the state the
                             badge wears - the Listings page offers this and
                             this page did not, though both show the same row
                             (#188, the owner 2026-09-02). --}}
                        @if($canToggleListing && $rowState?->state === \App\Integrations\Listings\ListingState::LIVE)
                            <form method="POST" action="{{ route('ext.shopee.listings.toggle', $p->product_id) }}" data-slow-action
                                  data-confirm="Unlist {{ $pName ?: 'this product' }} from Shopee? Buyers stop seeing it immediately. It can be relisted at any time." data-confirm-verb="Unlist">
                                @csrf
                                <input type="hidden" name="action" value="unlist">
                                <button type="submit" class="x-menu__item">Unlist from Shopee</button>
                            </form>
                        @elseif($canToggleListing && $rowState?->state === \App\Integrations\Listings\ListingState::INACTIVE)
                            <form method="POST" action="{{ route('ext.shopee.listings.toggle', $p->product_id) }}" data-slow-action
                                  data-confirm="Relist {{ $pName ?: 'this product' }} on Shopee? Buyers see it again immediately." data-confirm-tone="primary" data-confirm-verb="Relist">
                                @csrf
                                <input type="hidden" name="action" value="list">
                                <button type="submit" class="x-menu__item">Relist on Shopee</button>
                            </form>
                        @endif
                    @endif

                    <a class="x-menu__item" href="{{ route('ext.shopee.listings.edit', $p->product_id) }}">Open listing</a>
                    @if($canEditCatalog)<a class="x-menu__item" href="{{ route('products.edit', $p->product_id) }}">Edit in catalog</a>@endif

                    @if($isManual)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.shopee.product-groups.removeProduct', [$group->id, $p->product_id]) }}"
                              data-confirm="Take {{ $pName ?: 'this product' }} out of the group? Nothing is deleted from the catalog, and nothing already live on Shopee is touched." data-confirm-verb="Remove from group">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Remove from group</button>
                        </form>
                    @endif

                    @if($listed)
                        <div class="x-menu__sep"></div>
                        <form method="POST" action="{{ route('ext.shopee.product-groups.unlinkProduct', [$group->id, $p->product_id]) }}" data-slow-action
                              data-confirm="Unlink {{ $pName ?: 'this product' }} from Shopee? The listing stays on Shopee; the ERP just stops tracking it. Send will re-find it." data-confirm-verb="Unlink">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Unlink from Shopee</button>
                        </form>
                        <form method="POST" action="{{ route('ext.shopee.product-groups.deleteFromShopee', $group->id) }}" data-slow-action
                              data-confirm="Delete {{ $pName ?: 'this product' }} from Shopee? It is removed at the marketplace and this cannot be undone." data-confirm-verb="Delete from Shopee">
                            @csrf
                            <input type="hidden" name="_return" value="{{ $returnTo }}">
                            <input type="hidden" name="ids[]" value="{{ $p->product_id }}">
                            <button type="submit" class="x-menu__item x-menu__item--danger">Delete from Shopee</button>
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

@if($canManageShopee)
{{-- Add one at a time. Same endpoints as before: a GET to search-products?q=
     for the results, and one POST to addProducts carrying `ids[]`. The results
     are built with createElement and textContent only. --}}
<section class="fm-section cc-unmatched"
         data-group-manual-add
         data-search-url="{{ route('ext.shopee.product-groups.searchProducts') }}"
         data-form="shopee-add-products">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Add a product by name</h2>
    </div>
    <p class="fm-section__note">For a product the group's filter does not pick up. Type at least two characters.</p>

    <form id="shopee-add-products" method="POST" action="{{ route('ext.shopee.product-groups.addProducts', $group->id) }}">
        @csrf
        <input type="hidden" name="_return" value="{{ $returnTo }}">
    </form>

    <div class="cc-link">
        <x-ui.input type="search" placeholder="Product name or SKU" aria-label="Search the catalog to add a product"
                    data-manual-search />
        <x-ui.button type="button" data-manual-search-go>Search</x-ui.button>
        <x-ui.button type="submit" variant="primary" form="shopee-add-products" data-manual-add disabled>Add selected</x-ui.button>
    </div>

    <div data-manual-results></div>
</section>
@endif

{{-- Work-in-progress overlay. A push to Shopee is a multi-second round trip
     and the page has to say so, or the operator clicks again. Rides
     components/modals.css's .modal-backdrop show/hide mechanics, the same
     single `.active` class the confirm dialog and the orders pages use. --}}
<div id="shopee-group-progress" class="modal-backdrop">
    {{-- A dialog, and one that speaks. This overlay covers the page for
         several seconds while a marketplace call is in flight and carried no
         role, no name and no live region, so the only signal that anything
         was happening was the spinner - which is to say none at all for
         anyone not looking at it. --}}
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

</div>{{-- /.cc-page --}}
@endsection
