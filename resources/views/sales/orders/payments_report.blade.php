@extends('layouts.blotter')
@section('title', 'Accounts receivable')

@section('content')
@php
    use App\Support\Money;

    $defaultCode = Money::defaultCode();

    $count = $rows->count();
    $totalDue = (float) $rows->sum(fn ($r) => (float) $r->order->total);
    $totalPaid = (float) $rows->sum('total_paid');
    $totalBalance = (float) $rows->sum('balance');
    $outstanding = $rows->where('is_paid', false)->count();

    $filters = [
        'all'    => 'All',
        'unpaid' => 'With Balance',
        'paid'   => 'Paid in Full',
    ];

    $hasSearch = $search !== '';
@endphp

<div class="rp-page">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">Accounts receivable</h1>
            <p class="x-page-sub">
                Orders added to receivables, and what is still owed on each.
                <x-ui.hint label="How these figures are counted">All figures in {{ $defaultCode }} ({{ Money::defaultSymbol() }}). An order taken in another currency is converted at the rate recorded on that order.</x-ui.hint>
            </p>
        </div>
    </div>

    <div class="x-segment-bar">
        <div class="x-segment" role="group" aria-label="Filter by settlement">
            @foreach($filters as $key => $label)
                <a class="x-segment__item {{ $filter === $key ? 'is-active' : '' }}"
                   href="{{ route('orders.payments_report', array_filter(['filter' => $key, 'search' => $search])) }}"
                   @if($filter === $key) aria-current="page" @endif>{{ $label }}<span
                    class="x-segment__count">{{ number_format($counts[$key] ?? 0) }}</span></a>
            @endforeach
        </div>
    </div>

    <form method="GET" action="{{ route('orders.payments_report') }}" class="x-filters">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <label class="x-sr" for="op-search">Search by customer or order number</label>
        <x-ui.input type="search" id="op-search" name="search" value="{{ $search }}"
                    placeholder="Customer or order number" class="x-filters__search" />
        <x-ui.button variant="primary" type="submit">Search</x-ui.button>
        @if($hasSearch)
            <a class="x-filters__reset" href="{{ route('orders.payments_report', ['filter' => $filter]) }}">Clear</a>
        @endif
    </form>

    @if($count === 0)
        <x-ui.empty
            title="{{ $hasSearch ? 'Nothing matches that search' : 'No orders here' }}"
            description="{{ $hasSearch
                ? 'Try part of a customer name, or the order number on its own.'
                : 'An order appears once payment tracking is switched on for it, from the order record.' }}" />
    @else

    <div class="rp-band op-band">
        <div class="rp-lead">
            <span class="rp-lead__k">Still owed</span>
            <span class="rp-lead__v {{ $totalBalance > 0 ? 'op-owing' : 'op-clear' }}">{{ Money::base($totalBalance) }}</span>
            <span class="rp-lead__sub">
                <span>{{ number_format($outstanding) }} of {{ number_format($count) }} {{ Str::plural('order', $count) }}</span>
            </span>
        </div>
        <div class="rp-facts">
            <div class="rp-fact">
                <span class="rp-fact__k">Invoiced</span>
                <span class="rp-fact__v">{{ Money::base($totalDue) }}</span>
            </div>
            <div class="rp-fact">
                <span class="rp-fact__k">Received</span>
                <span class="rp-fact__v">{{ Money::base($totalPaid) }}</span>
            </div>
        </div>
    </div>

    <div class="rp-scroll">
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th scope="col" class="x-col-id">Order</th>
                    <th scope="col">Customer</th>
                    <th scope="col" class="x-col-date">Placed</th>
                    <th scope="col" class="x-td-num">Invoiced</th>
                    <th scope="col" class="x-td-num">Received</th>
                    <th scope="col" class="x-td-num">Balance</th>
                    <th scope="col" class="op-col-count x-td-num">Payments</th>
                    <th scope="col" class="x-col-stat">State</th>
                </tr>
            </x-slot:head>

            @foreach($rows as $row)
                @php
                    $order = $row->order;
                    $state = $row->is_paid
                        ? ['label' => 'Settled', 'tone' => 'success']
                        : ($row->total_paid > 0
                            ? ['label' => 'Part paid', 'tone' => 'warning']
                            : ['label' => 'Unpaid', 'tone' => 'neutral']);
                @endphp
                <tr>
                    <td class="x-col-id" data-label="Order">
                        <a class="x-row-link x-num" href="{{ route('orders.show', $order->order_id) }}">{{ $order->order_id }}</a>
                    </td>
                    <td class="x-cell-strong" data-label="Customer">{{ trim($order->firstname . ' ' . $order->lastname) ?: 'Not recorded' }}</td>
                    <td class="x-col-date x-cell-muted x-num" data-label="Placed">{{ \Carbon\Carbon::parse($order->date_added)->format('Y-m-d') }}</td>
                    <td class="x-td-num" data-label="Invoiced">
                        <x-money :php="(float) $order->total"
                                 :foreign="$order->foreign_total !== null ? (float) $order->foreign_total : null"
                                 :foreign-code="$order->currency_code" />
                    </td>
                    <td class="x-td-num" data-label="Received">{{ Money::base((float) $row->total_paid) }}</td>
                    <td class="x-td-num {{ $row->balance > 0 ? 'op-owing' : '' }}" data-label="Balance">{{ Money::base((float) $row->balance) }}</td>
                    <td class="op-col-count x-td-num x-cell-muted x-num" data-label="Payments">{{ $row->payment_count }}</td>
                    <td class="x-col-stat" data-label="State">
                        <x-ui.badge :tone="$state['tone']">{{ $state['label'] }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <tr class="rp-total">
                <td colspan="3">Total, {{ number_format($count) }} {{ Str::plural('order', $count) }}</td>
                <td class="x-td-num" data-label="Invoiced">{{ Money::base($totalDue) }}</td>
                <td class="x-td-num" data-label="Received">{{ Money::base($totalPaid) }}</td>
                <td class="x-td-num" data-label="Balance">{{ Money::base($totalBalance) }}</td>
                <td colspan="2"></td>
            </tr>
        </x-ui.table>
    </div>

    @endif
</div>
@endsection
