{{-- A listing's variations as rows of the same table (the owner, 2026-09-06:
     the old stack inside the Product cell had its own four columns that
     lined up with nothing, so "0 in stock" and "₱1,300.00" sat beside the
     empty Stock and Price cells they belonged in).

     One <tr> per variation, straight after the product's row. Every cell
     sits under the table's own header: the variation's name and SKU under
     Product, its quantity under Stock, its price under Price. The cells
     this row has nothing for (ID, the channel id, the state, the actions)
     are rendered empty with the column's class so the widths hold; on a
     phone they are hidden and the row becomes a two-line card under its
     product (pages/channel-catalog.css, .cc-var-row).

     Shared by every channel's Listings page (extensions/CHANNEL-CONTRACT.md).

     A row that carries sold_here (VariationRows::forListing() given the
     page's store) gets a dot under Listing: green when this store sells the
     variation, red when its Sell here switch is off. On a phone, where the
     cells after Price are hidden, the dot sits beside the name instead.

     Props:
       rows    iterable  App\Support\VariationRows::forListing() rows for one product
       check   bool      the table carries the select column (manage tier)
       trail   string[]  the classes of the cells after Price, in header order --}}
@props(['rows' => [], 'check' => false, 'trail' => ['cc-col-chanid', 'cc-col-sync', 'x-td-actions']])

@foreach($rows as $vr)
    @php
        $vrName = trim((string) ($vr->option_value_name ?? ''));
        $vrSku = trim((string) ($vr->sku ?? ''));
        $vrQty = $vr->quantity ?? null;
        $vrPrice = (float) ($vr->absolute_price ?? 0);
        $vrImg = trim((string) ($vr->option_image ?? ''));
        $vrSrc = $vrImg !== '' ? asset('storage/' . ltrim($vrImg, '/')) : '';
        $vrSold = isset($vr->sold_here) ? (bool) $vr->sold_here : null;
        $vrDotLabel = $vrSold ? 'Sold on this store' : (($vr->missing_here ?? false) ? 'Not on this store' : 'Switched off on this store');
    @endphp
    <tr class="cc-var-row">
        @if($check)
            <td class="cc-col-check cc-var-row__gap"></td>
        @endif
        <td class="cc-col-id cc-var-row__gap"></td>

        <td data-label="Variation">
            <div class="cc-var-row__item">
                <span class="cc-var-row__thumb">@if($vrSrc)<img src="{{ $vrSrc }}" alt="" loading="lazy" decoding="async">@endif</span>
                <span class="cc-var-row__name">{{ $vrName !== '' ? $vrName : 'Unnamed variation' }}</span>
                @if($vrSku !== '')
                    <span class="cc-var-row__sku">{{ $vrSku }}</span>
                @endif
                @if(! is_null($vrSold))
                    <span class="cc-var-dot cc-var-dot--phone cc-var-dot--{{ $vrSold ? 'on' : 'off' }}" role="img" aria-label="{{ $vrDotLabel }}"></span>
                @endif
            </div>
        </td>

        <td class="cc-col-qty x-td-num" data-label="Stock">
            @if(!is_null($vrQty))<span class="x-num">{{ (int) $vrQty }}</span>@endif
        </td>

        <td class="cc-col-price x-td-num" data-label="Price">
            @if($vrPrice > 0)<x-money :php="$vrPrice" />@endif
        </td>

        @foreach($trail as $vrClass)
            <td class="{{ $vrClass }} cc-var-row__gap">@if($vrClass === 'cc-col-sync' && ! is_null($vrSold))<span class="cc-var-dot cc-var-dot--{{ $vrSold ? 'on' : 'off' }}" role="img" aria-label="{{ $vrDotLabel }}"></span>@endif</td>
        @endforeach
    </tr>
@endforeach
