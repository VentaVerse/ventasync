@extends('layouts.channel')
@section('breadcrumb', 'Coupons')
@section('title', 'TikTok Shop Coupons')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/coupon') ?? false;
    $statusMap = \Extensions\tiktok\Controllers\TikTokCouponController::STATUS_MAP;
    $money = fn ($v) => is_numeric($v) ? number_format((float) $v, 2) : (string) $v;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Coupons</h1>
        <p class="x-page-sub">
            {{ $coupons === null ? 'TikTok did not answer.' : number_format($coupons->count()) . ' ' . ($coupons->count() === 1 ? 'coupon' : 'coupons') . ' on TikTok Shop right now, fetched as this page loaded.' }}
        </p>
    </div>
</div>

<form method="GET" action="{{ route('ext.tiktok.coupons.index') }}" class="x-filters">
    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit aria-label="Coupon status">
            <option value="all" @selected($status === 'all')>All</option>
            @foreach($statusMap as $value => [$label, $tone])
                <option value="{{ strtolower($value) }}" @selected($status === strtolower($value))>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <noscript><x-ui.button type="submit" variant="secondary">View</x-ui.button></noscript>
</form>

@if($coupons === null)
    <x-ui.empty title="TikTok did not answer"
                :description="'The coupon list could not be fetched. Nothing shown here is guessed; reload to ask again.'">
        <x-slot:action>
            <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'TikTok Shop', 'raw' => $liveError, 'settingsRoute' => 'ext.tiktok.index'])</div>
        </x-slot:action>
    </x-ui.empty>
@elseif($coupons->isEmpty())
    <x-ui.empty title="No coupons here"
                :description="$status === 'all'
                    ? 'The shop has no coupons yet.' . ($canManage ? ' Create the first one below.' : '')
                    : 'No coupons in that state. Switch the filter to see the rest.'" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col">Coupon</th>
            <th scope="col">Reward</th>
            <th scope="col" class="x-td-num">Min spend</th>
            <th scope="col" class="x-td-num">Uses</th>
            <th scope="col">Window</th>
            <th scope="col">Status</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($coupons as $cp)
        @php
            $state = (string) ($cp['status'] ?? '');
            [$stateLabel, $stateTone] = $statusMap[$state] ?? [$state ?: 'Unknown', 'neutral'];
            $discount = $cp['discount'] ?? [];
            $threshold = $cp['threshold'] ?? [];
            $limits = $cp['usage_limits'] ?? [];
            $window = $cp['redemption_duration'] ?? ($cp['claim_duration'] ?? []);
            $canStop = $canManage && in_array($state, ['NOT_START', 'ONGOING'], true);
            $notStarted = $state === 'NOT_START';
        @endphp
        <tr>
            <td data-label="Coupon">
                <strong>{{ $cp['title'] ?? '' }}</strong>
                <div class="x-cell-muted">id {{ $cp['id'] ?? '' }}</div>
            </td>
            <td data-label="Reward">
                @if(($discount['type'] ?? '') === 'PERCENTAGE_OFF')
                    {{ (int) ($discount['percentage_off'] ?? 0) }}% off
                    @if(!empty($discount['max_discount']))
                        <div class="x-cell-muted">up to {{ $money($discount['max_discount']) }}</div>
                    @endif
                @else
                    {{ $money($discount['money_off'] ?? 0) }} off
                @endif
            </td>
            <td class="x-td-num" data-label="Min spend">
                @if(($threshold['type'] ?? '') === 'MIN_SPEND')
                    {{ $money($threshold['min_spend'] ?? 0) }}
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-num" data-label="Uses">
                <span class="x-num">{{ (int) ($cp['claimed_count'] ?? ($limits['claimed_count'] ?? 0)) }} of {{ (int) ($limits['total_claim_count'] ?? 0) }}</span>
            </td>
            <td data-label="Window">
                @if(!empty($window['start_time']))
                    {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $window['start_time'])->format('M j, Y H:i') }}
                    to {{ \Illuminate\Support\Carbon::createFromTimestamp((int) ($window['end_time'] ?? 0))->format('M j, Y H:i') }}
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
            <td data-label="Status"><x-ui.badge :tone="$stateTone">{{ $stateLabel }}</x-ui.badge></td>
            <td class="x-td-actions">
                @if($canStop)
                    <form method="POST" action="{{ route('ext.tiktok.coupons.deactivate', $cp['id']) }}"
                          data-confirm="{{ $notStarted
                              ? 'Cancel coupon ' . ($cp['title'] ?? '') . ' before it starts? Buyers never see it, and it cannot be brought back.'
                              : 'End coupon ' . ($cp['title'] ?? '') . ' now? Buyers can no longer claim or use it, and an ended coupon cannot restart.' }}"
                          data-confirm-verb="{{ $notStarted ? 'Cancel coupon' : 'End coupon' }}">
                        @csrf
                        <x-ui.button type="submit">{{ $notStarted ? 'Cancel before it starts' : 'End now' }}</x-ui.button>
                    </form>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManage)
<form method="POST" action="{{ route('ext.tiktok.coupons.store') }}" class="fm-page">
    @csrf
    <details class="fm-card fm-card--fold" @if($errors->any() || old('_token')) open @endif>
        <summary class="fm-card__head fm-card__head--summary"><h2 class="fm-card__title">Create a coupon</h2><span class="fm-card__hint">Opens the form</span></summary>
        <div class="fm-card__body">
            <p class="fm-section__note">A shop coupon: buyers claim it and spend it on anything in the shop. Give an amount off or a percent off, not both. TikTok wants the start in the future.</p>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Name (buyers see this)" for="cp-title" name="title">
                    <x-ui.input id="cp-title" name="title" maxlength="50" :value="old('title')" />
                </x-ui.field>
                <x-ui.field label="How many buyers can use it" for="cp-claims" name="total_claim_count">
                    <x-ui.input id="cp-claims" name="total_claim_count" type="number" step="1" min="1" :value="old('total_claim_count')" placeholder="100" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Amount off" for="cp-amount" name="money_off">
                    <x-ui.input id="cp-amount" name="money_off" type="number" step="0.01" min="1" :value="old('money_off')" placeholder="50.00" />
                </x-ui.field>
                <x-ui.field label="Or percent off" for="cp-percent" name="percentage_off">
                    <x-ui.input id="cp-percent" name="percentage_off" type="number" step="1" min="1" max="99" :value="old('percentage_off')" placeholder="10" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Cap (max amount a percent can take off)" for="cp-cap" name="max_discount">
                    <x-ui.input id="cp-cap" name="max_discount" type="number" step="0.01" min="1" :value="old('max_discount')" placeholder="100.00" />
                </x-ui.field>
                <x-ui.field label="Minimum spend" for="cp-min" name="min_spend">
                    <x-ui.input id="cp-min" name="min_spend" type="number" step="0.01" min="0" :value="old('min_spend')" placeholder="0" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="From" for="cp-from" name="starts_at">
                    <x-ui.input id="cp-from" name="starts_at" type="datetime-local" :value="old('starts_at')" />
                </x-ui.field>
                <x-ui.field label="To" for="cp-to" name="ends_at">
                    <x-ui.input id="cp-to" name="ends_at" type="datetime-local" :value="old('ends_at')" />
                </x-ui.field>
            </div>
            <div class="cc-head-actions">
                <x-ui.button type="submit" variant="primary">Create on TikTok Shop</x-ui.button>
            </div>
        </div>
    </details>
</form>
@endif

</div>
@endsection
