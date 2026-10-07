<div class="od-ledger">
    @foreach($breakdown->rows() as $row)
        @php
            $rowClass = match (true) {
                $row['kind'] === 'total' => 'od-ledger__row--total',
                $row['kind'] === 'group' => 'od-ledger__row--group',
                $row['level'] === 1 => 'od-ledger__row--child',
                default => '',
            };
        @endphp
        <div class="od-ledger__row {{ $rowClass }}">
            <span class="od-ledger__k">{{ $row['label'] }}</span>
            <span class="od-ledger__v">{{ \App\Support\Money::foreign($row['amount'], $currency) }}</span>
        </div>
    @endforeach
</div>
