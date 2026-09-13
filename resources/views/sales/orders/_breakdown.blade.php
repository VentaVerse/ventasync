<section class="od-section">
    <div class="od-section__head">
        <h2 class="od-section__title">{{ $breakdown->title }}</h2>
    </div>

    <div class="od-ledger">
        @foreach($breakdown->rows() as $row)
            @php
                $rowBase = \App\Services\OrderCurrencyService::toBase($row['amount'], $orderRate);
                $rowClass = match (true) {
                    $row['kind'] === 'total' => 'od-ledger__row--total',
                    $row['kind'] === 'group' => 'od-ledger__row--group',
                    $row['level'] === 1 => 'od-ledger__row--child',
                    default => '',
                };
            @endphp
            <div class="od-ledger__row {{ $rowClass }}">
                <span class="od-ledger__k">{{ $row['label'] }}</span>
                <span class="od-ledger__v"><x-money :php="$rowBase" :foreign="$dual ? $row['amount'] : null" :foreign-code="$order->currency_code" /></span>
            </div>
        @endforeach
    </div>
</section>
