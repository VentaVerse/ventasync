@extends('layouts.channel')
@section('breadcrumb', 'Vouchers')
@section('title', 'Shopee Vouchers')

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_shopee/voucher') ?? false;

    $stateMap = [
        'upcoming' => ['Upcoming', 'info'],
        'ongoing' => ['Ongoing', 'success'],
        'expired' => ['Expired', 'neutral'],
    ];
@endphp

<div class="cc-page">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Vouchers</h1>
        <p class="x-page-sub">
            {{ $vouchers === null ? 'Shopee did not answer.' : number_format($vouchers->count()) . ' ' . ($vouchers->count() === 1 ? 'voucher' : 'vouchers') . ' on Shopee right now, fetched as this page loaded.' }}
        </p>
    </div>
</div>

<form method="GET" action="{{ route('ext.shopee.vouchers.index') }}" class="x-filters">
    <div class="x-select-wrap x-filters__select">
        <select name="status" class="x-input" data-autosubmit aria-label="Voucher status">
            @foreach(['all' => 'All', 'upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'expired' => 'Expired'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <noscript><x-ui.button type="submit" variant="secondary">View</x-ui.button></noscript>
</form>

@if($vouchers === null)
    <x-ui.empty title="Shopee did not answer"
                :description="'The voucher list could not be fetched. Nothing shown here is guessed; reload to ask again.'">
        <x-slot:action>
            <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Shopee', 'raw' => $liveError, 'settingsRoute' => 'ext.shopee.index'])</div>
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
            [$stateLabel, $stateTone] = $stateMap[$v['state']] ?? [$v['state'], 'neutral'];
            $isPercent = (int) ($v['reward_type'] ?? 0) === 2 || isset($v['percentage']);
        @endphp
        <tr>
            <td data-label="Voucher">
                <strong>{{ $v['voucher_name'] ?? '' }}</strong>
                <div class="x-cell-muted"><span class="x-num">{{ $v['voucher_code'] ?? '' }}</span> &middot; id {{ $v['voucher_id'] ?? '' }}</div>
            </td>
            <td data-label="Reward">
                @if($isPercent)
                    {{ (int) ($v['percentage'] ?? 0) }}% off
                    @if(!empty($v['max_price']))
                        <div class="x-cell-muted">up to <x-money :php="(float) $v['max_price']" /></div>
                    @endif
                @else
                    <x-money :php="(float) ($v['discount_amount'] ?? 0)" /> off
                @endif
            </td>
            <td class="x-td-num" data-label="Min spend">
                @if((float) ($v['min_basket_price'] ?? 0) > 0)
                    <x-money :php="(float) $v['min_basket_price']" />
                @else
                    <span class="x-cell-muted">None</span>
                @endif
            </td>
            <td class="x-td-num" data-label="Uses">
                <span class="x-num">{{ (int) ($v['current_usage'] ?? 0) }} of {{ (int) ($v['usage_quantity'] ?? 0) }}</span>
            </td>
            <td data-label="Window">
                {{ \Illuminate\Support\Carbon::createFromTimestamp((int) ($v['start_time'] ?? 0))->format('M j, Y H:i') }}
                to {{ \Illuminate\Support\Carbon::createFromTimestamp((int) ($v['end_time'] ?? 0))->format('M j, Y H:i') }}
            </td>
            <td data-label="Status"><x-ui.badge :tone="$stateTone">{{ $stateLabel }}</x-ui.badge></td>
            <td class="x-td-actions">
                @if($canManage && $v['state'] === 'upcoming')
                    <form method="POST" action="{{ route('ext.shopee.vouchers.delete', $v['voucher_id']) }}"
                          data-confirm="Delete voucher {{ $v['voucher_code'] ?? '' }} before it starts? Buyers never see it and this cannot be undone." data-confirm-verb="Delete voucher">
                        @csrf
                        <x-ui.button type="submit" variant="danger">Delete</x-ui.button>
                    </form>
                @elseif($canManage && $v['state'] === 'ongoing')
                    <form method="POST" action="{{ route('ext.shopee.vouchers.end', $v['voucher_id']) }}"
                          data-confirm="End voucher {{ $v['voucher_code'] ?? '' }} now? Buyers can no longer claim or use it, and an ended voucher cannot restart." data-confirm-verb="End voucher">
                        @csrf
                        <x-ui.button type="submit">End now</x-ui.button>
                    </form>
                @endif
            </td>
        </tr>
    @endforeach
</x-ui.table>
@endif

@if($canManage)
<form method="POST" action="{{ route('ext.shopee.vouchers.store') }}" class="fm-page">
    @csrf
    <details class="fm-card fm-card--fold" @if($errors->any() || old('_token')) open @endif>
        <summary class="fm-card__head fm-card__head--summary"><h2 class="fm-card__title">Create a voucher</h2><span class="fm-card__hint">Opens the form</span></summary>
        <div class="fm-card__body">
            <p class="fm-section__note">A shop voucher: buyers claim it and spend it on anything in the shop. Give an amount off or a percent off, not both. Shopee wants the start in the future.</p>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Name (buyers see this)" for="vc-name" name="voucher_name">
                    <x-ui.input id="vc-name" name="voucher_name" maxlength="20" :value="old('voucher_name')" />
                </x-ui.field>
                <x-ui.field label="Code (5 letters or numbers)" for="vc-code" name="voucher_code">
                    <x-ui.input id="vc-code" name="voucher_code" maxlength="5" :value="old('voucher_code')" placeholder="CODE5" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Amount off" for="vc-amount" name="discount_amount">
                    <x-ui.input id="vc-amount" name="discount_amount" type="number" step="0.01" min="1" :value="old('discount_amount')" placeholder="50.00" />
                </x-ui.field>
                <x-ui.field label="Or percent off" for="vc-percent" name="percentage">
                    <x-ui.input id="vc-percent" name="percentage" type="number" step="1" min="1" max="99" :value="old('percentage')" placeholder="10" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Cap (max amount a percent can take off)" for="vc-cap" name="max_price">
                    <x-ui.input id="vc-cap" name="max_price" type="number" step="0.01" min="1" :value="old('max_price')" placeholder="100.00" />
                </x-ui.field>
                <x-ui.field label="Minimum spend" for="vc-min" name="min_basket_price">
                    <x-ui.input id="vc-min" name="min_basket_price" type="number" step="0.01" min="0" :value="old('min_basket_price')" placeholder="0" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="How many buyers can use it" for="vc-qty" name="usage_quantity">
                    <x-ui.input id="vc-qty" name="usage_quantity" type="number" step="1" min="1" :value="old('usage_quantity')" placeholder="100" />
                </x-ui.field>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="From" for="vc-from" name="starts_at">
                    <x-ui.input id="vc-from" name="starts_at" type="datetime-local" :value="old('starts_at')" />
                </x-ui.field>
                <x-ui.field label="To" for="vc-to" name="ends_at">
                    <x-ui.input id="vc-to" name="ends_at" type="datetime-local" :value="old('ends_at')" />
                </x-ui.field>
            </div>
            <div class="cc-head-actions">
                <x-ui.button type="submit" variant="primary">Create on Shopee</x-ui.button>
            </div>
        </div>
    </details>
</form>
@endif

</div>
@endsection
