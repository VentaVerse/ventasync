@php
    $poPeso = fn (?float $v) => $v === null ? '-' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));
    $poBal = $card['balance'];
    $poSum = $card['summary'];
    $poFacts = array_slice($poBal['facts'] ?? [], 0, 2);
@endphp
<section class="bl-panel po-store" aria-label="{{ $card['channel']->name }} · {{ $card['storeName'] }} payouts">
    <header class="po-store__head">
        <svg class="po-store__chip" width="22" height="22" viewBox="0 0 22 22" aria-hidden="true" focusable="false">
            <rect width="22" height="22" rx="7" fill="{{ $card['channel']->accent }}"></rect>
            <text x="11" y="11" text-anchor="middle" dominant-baseline="central">{{ Str::upper(Str::substr($card['channel']->name, 0, 1)) }}</text>
        </svg>
        <h3 class="po-store__name">{{ $card['channel']->name }} · {{ $card['storeName'] }}</h3>
        <a class="po-store__go" href="{{ $card['url'] }}">Payouts <x-ui.icon name="chevron-right" size="12" /></a>
    </header>

    <div class="po-store__body">
        @if($poBal && ($poBal['ok'] ?? false))
            <div class="po-store__lead">
                <span class="po-label">{{ $poBal['lead']['label'] }}</span>
                <span class="po-store__value">{{ $poPeso($poBal['lead']['amount']) }}</span>
            </div>
            @if($poFacts)
                <div class="po-store__facts">
                    @foreach($poFacts as $fact)
                        <div>
                            <span class="po-label">{{ $fact['label'] }}</span>
                            <span class="po-store__fact">{{ array_key_exists('text', $fact) ? $fact['text'] : $poPeso($fact['amount']) }}</span>
                            @if(!empty($fact['sub']))<span class="po-store__sub">{{ $fact['sub'] }}</span>@endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if(!empty($poBal['note']))
                <p class="po-store__note">{{ $poBal['note'] }}</p>
            @endif
        @elseif($poBal)
            <p class="po-note">{{ $card['channel']->name }} did not answer. Nothing is guessed, so no balance is shown.</p>
        @else
            <p class="po-note">Reading the balance from {{ $card['channel']->name }}. It shows on your next look.</p>
        @endif
    </div>

    @if($poSum && $poSum['not_paid_count'] > 0)
        <a class="po-store__foot po-store__foot--bad" href="{{ $card['url'] }}">
            <span>Not paid</span>
            <span><strong>{{ $poPeso($poSum['not_paid']) }}</strong> · {{ $poSum['not_paid_count'] === 1 ? '1 order' : $poSum['not_paid_count'] . ' orders' }}</span>
        </a>
    @elseif($poSum)
        <p class="po-store__foot po-store__foot--good"><x-ui.icon name="check" size="13" /> Every order paid or on time</p>
    @endif
</section>
