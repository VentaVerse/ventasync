{{-- One store's money at its marketplace, as a card (the owner, 2026-10-01):
     the dashboard rail and the Payouts report both draw it, from
     App\Services\Payouts\PayoutOverview::cards(). The lead figure and two
     facts, labelled exactly as the channel's own Seller Center labels them,
     then what the store has not been paid. A figure the channel does not
     share is named in the note, never guessed.
     Never waits on the marketplace: it shows what was last read, with its
     age, and says so when nothing has been read yet.

     $card  one entry of PayoutOverview::cards() --}}
@php
    $poPeso = fn (?float $v) => $v === null ? '—' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));
    $poBal = $card['balance'];
    $poSum = $card['summary'];
    $poFacts = array_slice($poBal['facts'] ?? [], 0, 2);
@endphp
<section class="po-card" aria-label="{{ $card['channel']->name }} · {{ $card['storeName'] }} payouts">
    <header class="po-card__head">
        <svg class="po-card__chip" width="22" height="22" viewBox="0 0 22 22" aria-hidden="true" focusable="false">
            <rect width="22" height="22" rx="7" fill="{{ $card['channel']->accent }}"></rect>
            <text x="11" y="11" text-anchor="middle" dominant-baseline="central">{{ Str::upper(Str::substr($card['channel']->name, 0, 1)) }}</text>
        </svg>
        <h3 class="po-card__name">{{ $card['channel']->name }} · {{ $card['storeName'] }}</h3>
        <a class="po-card__go" href="{{ $card['url'] }}">Payouts <x-ui.icon name="chevron-right" size="12" /></a>
    </header>

    <div class="po-card__body">
        @if($poBal && ($poBal['ok'] ?? false))
            <div class="po-card__lead">
                <span class="po-label">{{ $poBal['lead']['label'] }}</span>
                <span class="po-card__value">{{ $poPeso($poBal['lead']['amount']) }}</span>
            </div>
            @if($poFacts)
                <div class="po-card__facts">
                    @foreach($poFacts as $fact)
                        <div>
                            <span class="po-label">{{ $fact['label'] }}</span>
                            <span class="po-card__fact">{{ array_key_exists('text', $fact) ? $fact['text'] : $poPeso($fact['amount']) }}</span>
                            @if(!empty($fact['sub']))<span class="po-card__sub">{{ $fact['sub'] }}</span>@endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if(!empty($poBal['note']))
                <p class="po-card__note">{{ $poBal['note'] }}</p>
            @endif
        @elseif($poBal)
            <p class="po-note">{{ $card['channel']->name }} did not answer. Nothing is guessed, so no balance is shown.</p>
        @else
            <p class="po-note">Reading the balance from {{ $card['channel']->name }}. It shows on your next look.</p>
        @endif
    </div>

    @if($poSum && $poSum['not_paid_count'] > 0)
        <a class="po-card__foot po-card__foot--bad" href="{{ $card['url'] }}">
            <span>Not paid</span>
            <span><strong>{{ $poPeso($poSum['not_paid']) }}</strong> · {{ $poSum['not_paid_count'] === 1 ? '1 order' : $poSum['not_paid_count'] . ' orders' }}</span>
        </a>
    @elseif($poSum)
        <p class="po-card__foot po-card__foot--good"><x-ui.icon name="check" size="13" /> Every order paid or on time</p>
    @endif
</section>
