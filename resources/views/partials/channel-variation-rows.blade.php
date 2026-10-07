@props(['rows' => [], 'check' => false, 'trail' => ['cc-col-chanid', 'cc-col-sync', 'x-td-actions']])

@foreach($rows as $vr)
    @php
        $vrName = trim((string) ($vr->option_value_name ?? ''));
        $vrSku = trim((string) ($vr->sku ?? ''));
        $vrQty = $vr->quantity ?? null;
        $vrPrice = (float) ($vr->absolute_price ?? 0);
        $vrImg = trim((string) ($vr->option_image ?? ''));
        $vrSrc = $vrImg !== '' ? \App\Services\Media\ImageCache::url($vrImg) : '';
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
                <span class="cc-var-row__thumb">@if($vrSrc)<img src="{{ $vrSrc }}" alt="" loading="lazy" decoding="async" data-thumb>@endif</span>
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
