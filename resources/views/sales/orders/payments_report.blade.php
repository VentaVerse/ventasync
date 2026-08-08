@extends('layouts.shell')
@section('title', 'Payments')
{{-- No @section('breadcrumb'): Sales > Payments is a real nav entry, so
     Breadcrumbs already derives "Sales / Payments" from the URL and a leaf
     would print the word a second time. --}}

@section('content')
@php
    use App\Support\Money;

    // Every figure on this page is in the default currency. `order.total` is
    // stored converted (OrderCurrencyService's storage rule) and
    // order_payments.amount is recorded in the same currency the order detail
    // page prints it in, so the subtraction that produces a balance is
    // currency-coherent. A foreign order also carries the as-transacted
    // figure, which the order column shows underneath through <x-money>.
    $defaultCode = Money::defaultCode();

    $count = $rows->count();
    $totalDue = (float) $rows->sum(fn ($r) => (float) $r->order->total);
    $totalPaid = (float) $rows->sum('total_paid');
    $totalBalance = (float) $rows->sum('balance');
    $outstanding = $rows->where('is_paid', false)->count();

    $filters = [
        'all'    => 'Every order',
        'unpaid' => 'Still owing',
        'paid'   => 'Settled',
    ];

    // Counts are deliberately absent from the segment. paymentsReport()
    // applies the filter before handing the rows over, so a count taken here
    // would describe the filtered set on every tab and read as though the
    // other two were empty. The line under the segment says what is actually
    // on screen instead.
    $hasSearch = $search !== '';
@endphp

<div class="rp-page">

    @include('partials.flash')

    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">Payments</h1>
            <p class="x-page-sub">
                Orders with payment tracking switched on, and what is still owed on each.
                All figures in {{ $defaultCode }}.
            </p>
        </div>
    </div>

    <div class="x-segment-bar">
        <div class="x-segment" role="group" aria-label="Filter by settlement">
            @foreach($filters as $key => $label)
                <a class="x-segment__item {{ $filter === $key ? 'is-active' : '' }}"
                   href="{{ route('orders.payments_report', array_filter(['filter' => $key, 'search' => $search])) }}"
                   @if($filter === $key) aria-current="page" @endif>{{ $label }}</a>
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
            {{-- Not the accent. On the payouts report the lead figure is money
                 arriving, so green is right there; here it is money owed, and
                 the balance column already uses the warning token for exactly
                 that. Zero owed drops the colour entirely. --}}
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
                    <th class="x-col-id">Order</th>
                    <th>Customer</th>
                    <th class="x-col-date">Placed</th>
                    <th class="x-td-num">Invoiced</th>
                    <th class="x-td-num">Received</th>
                    <th class="x-td-num">Balance</th>
                    <th class="op-col-count x-td-num">Payments</th>
                    <th class="x-col-stat">State</th>
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
