@extends('layouts.channel')
@section('breadcrumb', 'Vouchers')
@section('title', 'Lazada Vouchers')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_lazada/voucher') ?? false;
    $statusMap = \Extensions\lazada\Controllers\LazadaVoucherController::STATUS_MAP;
    $money = fn ($v) => is_numeric($v) ? number_format((float) $v, 2) : (string) $v;
    $when = fn ($ms) => $ms ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $ms)->format('M j, Y H:i') : null;
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Vouchers</h1>
        <p class="x-page-sub">
            {{ $vouchers === null ? 'Lazada did not answer.' : number_format($vouchers->count()) . ' ' . ($vouchers->count() === 1 ? 'voucher' : 'vouchers') . ' on Lazada right now, fetched as this page loaded.' }}
        </p>
    </div>
</div>

<form method="GET" action="{{ route('ext.lazada.vouchers.index') }}" class="x-filters">
    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit aria-label="Voucher status">
            <option value="all" @selected($status === 'all')>All</option>
            @foreach(['not_start' => 'Upcoming', 'ongoing' => 'Ongoing', 'expired' => 'Expired'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <noscript><x-ui.button type="submit" variant="secondary">View</x-ui.button></noscript>
</form>

@if($vouchers === null)
    <x-ui.empty title="Lazada did not answer"
                :description="'The voucher list could not be fetched. Nothing shown here is guessed; reload to ask again.'">
        <x-slot:action>
            <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Lazada', 'raw' => $liveError, 'settingsRoute' => 'ext.lazada.index'])</div>
        </x-slot:action>
    </x-ui.empty>
@elseif($vouchers->isEmpty())
    <x-ui.empty title="No vouchers here"
                :description="$status === 'all'
                    ? 'The shop has no vouchers yet.' . ($canManage ? ' Create the first one below.' : '')
                    : 'No vouchers in that state. Switch the filter to see the rest.'" />
@else
<x-ui.table>
    <x-slot:head>
        <tr>
            <th scope="col">Voucher</th>
            <th scope="col">Reward</th>
            <th scope="col" class="x-td-num">Min spend</th>
            <th scope="col" class="x-td-num">Uses</th>
            <th scope="col">Window</th>
            <th scope="col">Status</th>
            <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
        </tr>
    </x-slot:head>

    @foreach($vouchers as $v)
        @php
            [$stateLabel, $stateTone] = $statusMap[$v['state']] ?? [$v['state'] !== '' ? ucfirst($v['state']) : 'Unknown', 'neutral'];
            $isPercent = str_contains(strtoupper((string) ($v['discount_type'] ?? '')), 'PERCENT');
            $canStop = $canManage && in_array($v['state'], ['not_start', 'upcoming', 'ongoing'], true);
            $notStarted = in_array($v['state'], ['not_start', 'upcoming'], true);
        @endphp
        <tr>
            <td data-label="Voucher">
                <strong>{{ $v['name'] ?? '' }}</strong>
                <div class="x-cell-muted">id {{ $v['id'] ?? ($v['voucher_id'] ?? '') }}</div>
            </td>
            <td data-label="Reward">
                @if($isPercent)
                    {{ (int) ($v['discount_value'] ?? 0) }}% off
                    @if(!empty($v['max_discount_offering_money_value']))
                        <div class="x-cell-muted">up to {{ $money($v['max_discount_offering_money_value']) }}</div>
                    @endif
                @else
                    {{ $money($v['discount_value'] ?? 0) }} off
                @endif
            </td>
            <td class="x-td-num" data-label="Min spend">
                @if((float) ($v['criteria_over_money'] ?? 0) > 0)
                    {{ $money($v['criteria_over_money']) }}
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-num" data-label="Uses"><span class="x-num">up to {{ (int) ($v['issued'] ?? 0) }}</span></td>
            <td data-label="Window">
                @if($when($v['period_start_time'] ?? null))
                    {{ $when($v['period_start_time']) }} to {{ $when($v['period_end_time'] ?? null) }}
                @else
                    <span class="x-cell-muted">Not set</span>
                @endif
            </td>
            <td data-label="Status"><x-ui.badge :tone="$stateTone">{{ $stateLabel }}</x-ui.badge></td>
            <td class="x-td-actions">
                @if($canStop)
                    <form method="POST" action="{{ route('ext.lazada.vouchers.deactivate', $v['id'] ?? ($v['voucher_id'] ?? 0)) }}"
                          data-confirm="{{ $notStarted
                              ? 'Cancel voucher ' . ($v['name'] ?? '') . ' before it starts? Buyers never see it, and it cannot be brought back.'
                              : 'End voucher ' . ($v['name'] ?? '') . ' now? Buyers can no longer collect or use it, and an ended voucher cannot restart.' }}"
                          data-confirm-verb="{{ $notStarted ? 'Cancel voucher' : 'End voucher' }}">
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
<form method="POST" action="{{ route('ext.lazada.vouchers.store') }}" class="fm-page">
    @csrf
    <details class="fm-card fm-card--fold" @if($errors->any() || old('_token')) open @endif>
        <summary class="fm-card__head fm-card__head--summary"><h2 class="fm-card__title">Create a voucher</h2><span class="fm-card__hint">Opens the form</span></summary>
        <div class="fm-card__body">
            <p class="fm-section__note">A collectible shop voucher: buyers collect it and spend it on anything in the shop. Give an amount off or a percent off, not both. Lazada wants the start in the future.</p>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Name (buyers see this)" for="lv-name" name="name">
                    <x-ui.input id="lv-name" name="name" maxlength="50" :value="old('name')" />
                </x-ui.field>
                <x-ui.field label="How many buyers can use it" for="lv-issued" name="issued">
                    <x-ui.input id="lv-issued" name="issued" type="number" step="1" min="1" :value="old('issued')" placeholder="100" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Amount off" for="lv-amount" name="money_off">
                    <x-ui.input id="lv-amount" name="money_off" type="number" step="0.01" min="1" :value="old('money_off')" placeholder="50.00" />
                </x-ui.field>
                <x-ui.field label="Or percent off" for="lv-percent" name="percentage_off">
                    <x-ui.input id="lv-percent" name="percentage_off" type="number" step="1" min="1" max="99" :value="old('percentage_off')" placeholder="10" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Cap (max amount a percent can take off)" for="lv-cap" name="max_discount">
                    <x-ui.input id="lv-cap" name="max_discount" type="number" step="0.01" min="1" :value="old('max_discount')" placeholder="100.00" />
                </x-ui.field>
                <x-ui.field label="Minimum spend" for="lv-min" name="min_spend">
                    <x-ui.input id="lv-min" name="min_spend" type="number" step="0.01" min="0" :value="old('min_spend')" placeholder="0" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="From" for="lv-from" name="starts_at">
                    <x-ui.input id="lv-from" name="starts_at" type="datetime-local" :value="old('starts_at')" />
                </x-ui.field>
                <x-ui.field label="To" for="lv-to" name="ends_at">
                    <x-ui.input id="lv-to" name="ends_at" type="datetime-local" :value="old('ends_at')" />
                </x-ui.field>
            </div>
            <div class="cc-head-actions">
                <x-ui.button type="submit" variant="primary">Create on Lazada</x-ui.button>
            </div>
        </div>
    </details>
</form>
@endif

</div>
@endsection
