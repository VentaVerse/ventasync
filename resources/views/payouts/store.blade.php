@extends('layouts.channel')
@section('breadcrumb', 'Payouts')
@section('title', $channelName . ' Payouts · ' . $storeName)

{{-- One store's Payouts page, under its Sell menu (the owner, 2026-10-01; the
     approved draft). Shopee, Lazada and TikTok share it; the channel's
     controller hands over its figures (App\Services\Payouts\StorePayoutsPage).

     Two panels: the money at the marketplace now, read live and in its
     own Seller Center's words, and the orders it has not truly paid for.
     "Refunded · shipping charged" is counted apart: that money was already
     taken from the balance, so it is a cost, never a debt. --}}
@section('content')
@php
    $peso = fn (?float $v) => $v === null ? '—' : (($v < 0 ? '−₱' : '₱') . number_format(abs($v), 2));

    $flags = [
        'overdue' => ['label' => 'Overdue', 'tone' => 'warning',
            'blurb' => "Done more than {$overdueDays} days ago and still not credited. A normal release takes days, not a month."],
        'short' => ['label' => 'Paid short', 'tone' => 'warning',
            'blurb' => 'Credited, but less than the payout ' . $channelName . ' showed when the order completed.'],
        'failed' => ['label' => 'Payment failed', 'tone' => 'danger',
            'blurb' => $channelName . ' says it tried to pay these and the payment failed.'],
        'nopayout' => ['label' => 'No payout found', 'tone' => 'danger',
            'blurb' => 'Sync Payouts looked back as far as it reads and found no payment. Worth raising with ' . $channelName . '.'],
        'refunded' => ['label' => 'Refunded · shipping charged', 'tone' => 'info',
            'blurb' => 'Returned and refunded to the buyer in full; ' . $channelName . ' kept the shipping from your balance. Nothing is owed: it has already been taken.'],
    ];

    $counts = $attention['counts'];
    $total = count($attention['rows']);
    $segments = ['all' => ['label' => 'All', 'n' => $total]];
    foreach ($flags as $key => $f) {
        if ($counts[$key] > 0 || ($key === 'short' && $hasShort) || $flag === $key) {
            $segments[$key] = ['label' => $f['label'], 'n' => $counts[$key]];
        }
    }

    $checkedAt = isset($balance['checked_at']) ? \Illuminate\Support\Carbon::createFromTimestamp($balance['checked_at'])->setTimezone(config('app.timezone')) : null;
    $orderUrl = function (array $r) use ($descriptor) {
        $value = $descriptor['order_key'] === 'id' ? $r['id'] : $r['ref'];

        return route($descriptor['order_route'], [$descriptor['order_param'] => $value]);
    };
@endphp

<div class="cc-page po-page">

<div class="x-list-head po-head">
    <div>
        <h1 class="x-page-title">Payouts</h1>
        <p class="x-page-sub">Whether {{ $channelName }} has paid {{ $storeName }} for its orders, and what {{ $channelName }} is holding for you now.</p>
    </div>
    <div class="po-head__actions">
        @if($job?->last_run_at)
            <span class="po-checked">
                <x-ui.icon name="clock" size="14" />
                Payouts checked <time datetime="{{ $job->last_run_at->toIso8601String() }}" title="{{ $job->last_run_at->format('j M Y, H:i') }}">{{ $job->last_run_at->diffForHumans() }}</time>
            </span>
        @endif
        @if($canRun)
            <x-ui.button type="submit" form="automation-run-{{ $job->id }}" title="Ask {{ $channelName }} again which orders have been paid">
                <x-ui.icon name="refresh-cw" size="14" /> Run Sync Payouts
            </x-ui.button>
        @endif
    </div>
</div>

{{-- The money at the marketplace, now --}}
<section class="po-panel po-money" aria-labelledby="po-money-h">
    <header class="po-panel__head">
        <h2 id="po-money-h" class="po-panel__title">Money at {{ $channelName }}</h2>
        @if($balance['ok'] ?? false)
            <span class="po-live">
                <x-ui.badge tone="success">Live from {{ $channelName }} · {{ $checkedAt?->diffForHumans() }}</x-ui.badge>
                @if($checkedAt && $checkedAt->diffInSeconds(now()) >= 60)
                    <a class="po-live__again" href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}">Read again</a>
                @endif
            </span>
        @endif
    </header>

    @if($balance['ok'] ?? false)
        <div class="po-money__grid po-money__grid--{{ min(4, max(1, count($balance['facts']))) }}">
            <div class="po-lead">
                <span class="po-label">{{ $balance['lead']['label'] }}</span>
                <span class="po-lead__value">{{ $peso($balance['lead']['amount']) }}</span>
                @if(!empty($balance['lead']['note']))<span class="po-note">{{ $balance['lead']['note'] }}</span>@endif
            </div>
            @foreach($balance['facts'] as $fact)
                <div class="po-fact">
                    <span class="po-label">{{ $fact['label'] }}</span>
                    <span class="po-fact__value">{{ array_key_exists('text', $fact) ? $fact['text'] : $peso($fact['amount']) }}</span>
                    @if(!empty($fact['sub']))<span class="po-note">{{ $fact['sub'] }}</span>@endif
                </div>
            @endforeach
        </div>
        @if(!empty($balance['note']))
            <p class="po-money__note"><x-ui.icon name="info" size="14" /> {{ $balance['note'] }}</p>
        @endif
    @else
        <div class="po-money__down">
            <p class="po-money__down-title">{{ $channelName }} did not answer</p>
            <p class="po-note">Nothing here is guessed, so no balance is shown. {{ $balance['error'] ?? '' }}
                <a href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}">Try again</a></p>
        </div>
    @endif
</section>

{{-- The orders it has not truly paid for --}}
<section class="po-panel po-attn" aria-labelledby="po-attn-h">
    <header class="po-attn__head">
        <div class="po-attn__intro">
            <h2 id="po-attn-h" class="po-panel__title">Needs attention</h2>
            <p class="po-attn__sub">Orders {{ $channelName }} marked done but has not paid you for, or paid less than it said it would.</p>
        </div>
        <dl class="po-totals">
            <div class="po-total {{ $attention['not_paid_count'] > 0 ? 'po-total--bad' : '' }}">
                <dt class="po-label">Not paid</dt>
                <dd class="po-total__value">{{ $peso($attention['not_paid']) }}</dd>
                <dd class="po-note">{{ $attention['not_paid_count'] === 0 ? 'every order paid or on time' : ($attention['not_paid_count'] === 1 ? '1 order' : $attention['not_paid_count'] . ' orders') }}</dd>
            </div>
            <div class="po-total">
                <dt class="po-label">Shipping charged on refunds</dt>
                <dd class="po-total__value">{{ $peso($attention['charged']) }}</dd>
                <dd class="po-note">{{ $attention['charged_count'] === 0 ? 'no refunds' : ($attention['charged_count'] === 1 ? '1 refund' : $attention['charged_count'] . ' refunds') }}</dd>
            </div>
        </dl>
    </header>

    @if($total > 0)
        <nav class="po-segs" aria-label="Show orders that are">
            @foreach($segments as $key => $seg)
                <a class="po-seg {{ $flag === $key ? 'is-on' : '' }}" @if($flag === $key) aria-current="true" @endif
                   href="{{ request()->fullUrlWithQuery(['flag' => $key === 'all' ? null : $key, 'refresh' => null]) }}">
                    {{ $seg['label'] }} <span class="po-seg__n">{{ $seg['n'] }}</span>
                </a>
            @endforeach
        </nav>
        <p class="po-blurb">{{ $flag === 'all' ? 'Every order this store has not been paid for as expected.' : $flags[$flag]['blurb'] }}</p>

        @if(count($rows) === 0)
            <x-ui.empty title="Nothing here" description="No order is in this state. Switch the filter to see the rest." />
        @else
            <x-ui.table class="po-table" caption="Orders not paid as expected">
                <x-slot:head>
                    <tr>
                        <th scope="col">Flag</th>
                        <th scope="col">{{ $channelName }} order</th>
                        <th scope="col">VentaSync</th>
                        <th scope="col">Done</th>
                        <th scope="col" class="x-td-num">Waiting</th>
                        <th scope="col" class="x-td-num">Expected payout</th>
                        <th scope="col" class="x-td-num">Received</th>
                        <th scope="col" class="x-td-num">Difference</th>
                    </tr>
                </x-slot:head>
                @foreach($rows as $r)
                    @php
                        $refunded = $r['flag'] === 'refunded';
                        $diff = $refunded ? $r['expected'] : (($r['received'] ?? 0) - ($r['expected'] ?? 0));
                    @endphp
                    <tr>
                        <td data-label="Flag"><x-ui.badge :tone="$flags[$r['flag']]['tone']">{{ $flags[$r['flag']]['label'] }}</x-ui.badge></td>
                        <td data-label="{{ $channelName }} order"><a class="po-ref" href="{{ $orderUrl($r) }}">{{ $r['ref'] }}</a></td>
                        <td data-label="VentaSync">
                            @if($r['catalog_order_id'])
                                <a class="x-num" href="{{ route('orders.show', $r['catalog_order_id']) }}">#{{ $r['catalog_order_id'] }}</a>
                            @else
                                <span class="x-cell-muted">Not linked</span>
                            @endif
                        </td>
                        <td data-label="Done">{{ $r['done']?->format('j M Y') ?? '—' }}</td>
                        <td data-label="Waiting" class="x-td-num">{{ $r['waiting_days'] === 1 ? '1 day' : $r['waiting_days'] . ' days' }}</td>
                        <td data-label="Expected payout" class="x-td-num">
                            @if($refunded)
                                Refunded
                            @else
                                {{ $peso($r['expected']) }}@if($r['estimated'])<abbr class="po-est" title="{{ $channelName }} has not reported this order's fees, so this is the order total">est.</abbr>@endif
                            @endif
                        </td>
                        <td data-label="Received" class="x-td-num x-cell-muted">{{ $refunded ? '—' : $peso($r['received']) }}</td>
                        <td data-label="Difference" class="x-td-num">
                            <span class="{{ $refunded ? '' : 'po-neg' }}">{{ $peso($diff) }}{{ $refunded ? ' shipping' : '' }}</span>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    @else
        <div class="po-clean">
            <x-ui.icon name="check" size="22" />
            <p class="po-clean__title">Every delivered order is paid or on time</p>
            <p class="po-note">{{ $channelName }} has paid every order it should have by now. Orders still inside the normal {{ $overdueDays }}-day release time are not listed.</p>
        </div>
    @endif
</section>

</div>

@if($canRun)
    <form id="automation-run-{{ $job->id }}" method="POST" action="{{ route('automations.run', $job->id) }}" data-run-name="Sync Payouts">
        @csrf
    </form>
    @include('partials.automation-run-modal', ['stepUrl' => route('automations.run_step', ['run' => 0]), 'stopUrl' => route('automations.run_stop', ['run' => 0])])
@endif
@endsection
