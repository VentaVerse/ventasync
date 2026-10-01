{{-- The money at each marketplace, one panel per store, side by side, on
     the Payouts report
     (the owner, 2026-10-01: the approved original, draft #1). Each panel
     carries its own Seller Center's words and only figures its API gives
     exactly; what a channel keeps to itself is said, never guessed. --}}
@php
    $mpPeso = fn (?float $v) => $v === null ? '—' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));
@endphp
<section class="mp" aria-labelledby="mp-h">
    <div class="mp__head">
        <h2 id="mp-h" class="mp__title">Money at each marketplace</h2>
        <span class="mp__aside">In each Seller Center's own words, as last read from its API</span>
    </div>
    <div class="mp__grid">
        @foreach($payoutCards as $card)
            @php $bal = $card['balance']; $sum = $card['summary']; $ok = $bal && ($bal['ok'] ?? false); @endphp
            <article class="mp-card">
                <header class="mp-card__head">
                    <svg class="mp-card__chip" width="28" height="28" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                        <rect width="28" height="28" rx="8" fill="{{ $card['channel']->accent }}"></rect>
                        <text x="14" y="14" text-anchor="middle" dominant-baseline="central">{{ Str::upper(Str::substr($card['channel']->name, 0, 1)) }}</text>
                    </svg>
                    <div class="mp-card__who">
                        <a class="mp-card__name" href="{{ $card['url'] }}">{{ $card['channel']->name }}</a>
                        <span class="mp-card__store">{{ $card['storeName'] }}</span>
                    </div>
                </header>

                @if($ok)
                    <div class="mp-card__lead">
                        <span class="mp-card__k">{{ $bal['lead']['label'] }}</span>
                        <span class="mp-card__v">{{ $mpPeso($bal['lead']['amount']) }}</span>
                    </div>
                    <dl class="mp-card__rows">
                        @foreach($bal['facts'] as $f)
                            <div class="mp-card__row">
                                <dt>{{ $f['label'] }}@if(!empty($f['sub']))<span class="mp-card__sub">{{ $f['sub'] }}</span>@endif</dt>
                                <dd>{{ array_key_exists('text', $f) ? $f['text'] : $mpPeso($f['amount']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if(!empty($bal['note']))<p class="mp-card__note">{{ $bal['note'] }}</p>@endif
                @elseif($bal)
                    <p class="mp-card__note">{{ $card['channel']->name }} did not answer, so no balance is shown.</p>
                @else
                    <p class="mp-card__note">Reading the balance from {{ $card['channel']->name }}. It shows on your next look.</p>
                @endif

                @if($sum && $sum['not_paid_count'] > 0)
                    <a class="mp-card__foot mp-card__foot--attn" href="{{ $card['url'] }}">
                        <x-ui.icon name="alert-triangle" size="15" />
                        <span>{{ $sum['not_paid_count'] }} not paid</span>
                        <strong>{{ $mpPeso($sum['not_paid']) }}</strong>
                    </a>
                @elseif($sum)
                    <p class="mp-card__foot mp-card__foot--good"><x-ui.icon name="check" size="15" /> <span>Every delivered order is paid or on time</span></p>
                @endif
            </article>
        @endforeach
    </div>
</section>
