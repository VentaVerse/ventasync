{{-- The Needs attention rows (the owner, 2026-10-01), for one store's Payouts
     page and for every store on the Payouts report.

     The order date is shown, never "done": done is the order's last update,
     which a marketplace can touch years later. Days unpaid only where money
     is still missing; a refund is finished business and has none.

     $rows       PayoutAttention rows, each with order_url (and, for many
                 stores, channel + storeName)
     $flags      the flag labels and tones
     $showStore  whether to add the Store column
     $orderHead  the order column's heading
     $activeFlag the filter the page opened on ('all' by default)
     $limit      rows shown before "Show all" (0 = every row)

     Every row is rendered; the filter tabs show and hide them in place
     (resources/js/pages/payouts.js), so pressing one never reloads or
     scrolls the page. Without the script the tabs are plain links. --}}
@php
    $activeFlag ??= 'all';
    $limit ??= 0;
    $atShown = 0;
    $atPeso = fn (?float $v) => $v === null ? '—' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));
@endphp
<x-ui.table class="po-table" caption="Orders not paid as expected">
    <x-slot:head>
        <tr>
            <th scope="col">Flag</th>
            @if($showStore)<th scope="col">Store</th>@endif
            <th scope="col">{{ $orderHead }}</th>
            <th scope="col">VentaSync</th>
            <th scope="col">Placed</th>
            <th scope="col" class="x-td-num">Days unpaid</th>
            <th scope="col" class="x-td-num">Expected payout</th>
            <th scope="col" class="x-td-num">Received</th>
            <th scope="col" class="x-td-num">Difference</th>
        </tr>
    </x-slot:head>
    @foreach($rows as $r)
        @php
            $atMatch = $activeFlag === 'all' || $r['flag'] === $activeFlag;
            $atHidden = ! $atMatch || ($limit > 0 && $atShown >= $limit);
            if (! $atHidden) { $atShown++; }
            $atRefunded = $r['flag'] === 'refunded';
            $atDiff = $atRefunded ? $r['expected'] : (($r['received'] ?? 0) - ($r['expected'] ?? 0));
        @endphp
        <tr data-po-row data-po-flag="{{ $r['flag'] }}" @if($atHidden) hidden @endif>
            <td data-label="Flag"><x-ui.badge :tone="$flags[$r['flag']]['tone']">{{ $flags[$r['flag']]['label'] }}</x-ui.badge></td>
            @if($showStore)
                <td data-label="Store">
                    <span class="po-store">
                        <svg class="po-store__chip" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
                            <rect width="18" height="18" rx="6" fill="{{ $r['channel']->accent }}"></rect>
                            <text x="9" y="9" text-anchor="middle" dominant-baseline="central">{{ Str::upper(Str::substr($r['channel']->name, 0, 1)) }}</text>
                        </svg>
                        {{ $r['channel']->name }} · {{ $r['storeName'] }}
                    </span>
                </td>
            @endif
            <td data-label="{{ $orderHead }}"><a class="po-ref" href="{{ $r['order_url'] }}">{{ $r['ref'] }}</a></td>
            <td data-label="VentaSync">
                @if($r['catalog_order_id'])
                    <a class="x-num" href="{{ route('orders.show', $r['catalog_order_id']) }}">#{{ $r['catalog_order_id'] }}</a>
                @else
                    <span class="x-cell-muted">Not linked</span>
                @endif
            </td>
            <td data-label="Placed">{{ $r['placed']?->format('j M Y') ?? '—' }}</td>
            <td data-label="Days unpaid" class="x-td-num">{{ $r['days_unpaid'] === null ? '' : number_format($r['days_unpaid']) }}</td>
            <td data-label="Expected payout" class="x-td-num">
                @if($atRefunded)
                    Refunded
                @else
                    {{ $atPeso($r['expected']) }}@if($r['estimated'])<abbr class="po-est" title="The marketplace has not reported this order's fees, so this is the order total">est.</abbr>@endif
                @endif
            </td>
            <td data-label="Received" class="x-td-num x-cell-muted">{{ $atRefunded ? '—' : $atPeso($r['received']) }}</td>
            <td data-label="Difference" class="x-td-num">
                <span class="{{ $atRefunded ? '' : 'po-neg' }}">{{ $atPeso($atDiff) }}{{ $atRefunded ? ' shipping' : '' }}</span>
            </td>
        </tr>
    @endforeach
</x-ui.table>
