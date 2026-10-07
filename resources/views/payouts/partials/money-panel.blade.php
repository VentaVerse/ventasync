@php
    $mpPeso = fn (?float $v) => $v === null ? '-' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));
    $bal = $card['balance'];
    $sum = $card['summary'];
    $ok = $bal && ($bal['ok'] ?? false);
    $compact ??= false;
@endphp
<article class="bl-sheet mp-store">
    @if($compact)
        <header class="mp-store__head mp-store__head--compact">
            <h2 class="mp-store__title">Payouts</h2>
            @if($card['report_url'])
                <a class="mp-store__more" href="{{ $card['report_url'] }}">Payouts report <x-ui.icon name="chevron-right" size="12" /></a>
            @endif
        </header>
    @else
        <header class="mp-store__head">
            <svg class="mp-store__chip" width="28" height="28" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                <rect width="28" height="28" rx="8" fill="{{ $card['channel']->accent }}"></rect>
                <text x="14" y="14" text-anchor="middle" dominant-baseline="central">{{ Str::upper(Str::substr($card['channel']->name, 0, 1)) }}</text>
            </svg>
            <div class="mp-store__who">
                <a class="mp-store__name" href="{{ $card['url'] }}">{{ $card['channel']->name }}</a>
                <span class="mp-store__store">{{ $card['storeName'] }}</span>
            </div>
        </header>
    @endif

    @if($ok)
        <div class="mp-store__lead">
            <span class="mp-store__k">{{ $bal['lead']['label'] }}</span>
            <span class="mp-store__v">{{ $mpPeso($bal['lead']['amount']) }}</span>
        </div>
        <dl class="mp-store__rows">
            @foreach($bal['facts'] as $f)
                <div class="mp-store__row">
                    <dt>{{ $f['label'] }}@if(!empty($f['sub']))<span class="mp-store__sub">{{ $f['sub'] }}</span>@endif</dt>
                    <dd>{{ array_key_exists('text', $f) ? $f['text'] : $mpPeso($f['amount']) }}</dd>
                </div>
            @endforeach
        </dl>
        @if(!empty($bal['note']))<p class="mp-store__note">{{ $bal['note'] }}</p>@endif
    @elseif($bal)
        <p class="mp-store__note">{{ $card['channel']->name }} did not answer, so no balance is shown.</p>
    @else
        <p class="mp-store__note">Reading the balance from {{ $card['channel']->name }}. It shows on your next look.</p>
    @endif

    @if($sum && $sum['not_paid_count'] > 0)
        <a class="mp-store__foot mp-store__foot--attn" href="{{ $card['unpaid_url'] ?? $card['url'] }}">
            <x-ui.icon name="alert-triangle" size="15" />
            <span>{{ $sum['not_paid_count'] }} not paid</span>
            <strong>{{ $mpPeso($sum['not_paid']) }}</strong>
        </a>
    @elseif($sum)
        <p class="mp-store__foot mp-store__foot--good"><x-ui.icon name="check" size="15" /> <span>{{ $compact ? 'All paid or on time' : 'Every delivered order is paid or on time' }}</span></p>
    @endif
</article>
