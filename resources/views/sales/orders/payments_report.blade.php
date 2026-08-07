@extends('layouts.app')
@section('breadcrumb', 'Sales / Order Payments')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Order Payments</h2>
    </div>

    {{-- Search + Filter --}}
    <div class="d-flex items-center justify-between gap-12 mb-16 flex-wrap">
        <div class="d-flex gap-8">
            <a href="{{ route('orders.payments_report', ['filter' => 'all', 'search' => $search]) }}" class="btn small {{ $filter === 'all' ? '' : 'secondary' }}">
                All ({{ $rows->count() }})
            </a>
            <a href="{{ route('orders.payments_report', ['filter' => 'unpaid', 'search' => $search]) }}" class="btn small {{ $filter === 'unpaid' ? '' : 'secondary' }}">
                With Balance ({{ $rows->where('is_paid', false)->count() }})
            </a>
            <a href="{{ route('orders.payments_report', ['filter' => 'paid', 'search' => $search]) }}" class="btn small {{ $filter === 'paid' ? '' : 'secondary' }}">
                Paid in Full ({{ $rows->where('is_paid', true)->count() }})
            </a>
        </div>
        <form method="GET" action="{{ route('orders.payments_report') }}" class="d-flex gap-8 items-center">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="text" name="search" class="input" value="{{ $search }}" placeholder="Search by name or order #" style="min-width:220px; height:34px; font-size:13px;">
            <button type="submit" class="btn small">Search</button>
            @if($search)
                <a href="{{ route('orders.payments_report', ['filter' => $filter]) }}" class="btn small secondary">Clear</a>
            @endif
        </form>
    </div>

    @if($rows->count())
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Date</th>
                <th class="text-right">Order Total</th>
                <th class="text-right">Total Paid</th>
                <th class="text-right">Balance</th>
                <th class="text-center">Payments</th>
                <th class="text-center">Status</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows as $row)
            <tr>
                <td>
                    <a href="{{ route('orders.show', $row->order->order_id) }}" style="color:var(--accent); font-weight:600;">
                        #{{ $row->order->order_id }}
                    </a>
                </td>
                <td>{{ $row->order->firstname }} {{ $row->order->lastname }}</td>
                <td class="text-sm text-secondary">{{ $row->order->date_added }}</td>
                <td class="text-right">{{ number_format((float) $row->order->total, 2) }}</td>
                <td class="text-right font-semibold">{{ number_format($row->total_paid, 2) }}</td>
                <td class="text-right font-semibold" style="color: {{ $row->balance > 0 ? 'var(--warning)' : 'var(--success)' }}">
                    {{ number_format($row->balance, 2) }}
                </td>
                <td class="text-center">{{ $row->payment_count }}</td>
                <td class="text-center">
                    @if($row->is_paid)
                        <span class="badge badge-green">Paid</span>
                    @elseif($row->total_paid > 0)
                        <span class="badge badge-yellow">Partial</span>
                    @else
                        <span class="badge badge-red">Unpaid</span>
                    @endif
                </td>
            </tr>
            @endforeach
            @if($rows->count() > 1)
            <tr style="border-top:2px solid var(--border); background:var(--surface-alt);">
                <td colspan="3" class="text-right font-bold">Totals</td>
                <td class="text-right font-bold">{{ number_format($rows->sum(fn($r) => (float) $r->order->total), 2) }}</td>
                <td class="text-right font-bold">{{ number_format($rows->sum('total_paid'), 2) }}</td>
                @php $totalBalance = $rows->sum('balance'); @endphp
                <td class="text-right font-bold" style="color: {{ $totalBalance > 0 ? 'var(--warning)' : 'var(--success)' }}">
                    {{ number_format($totalBalance, 2) }}
                </td>
                <td colspan="2"></td>
            </tr>
            @endif
            </tbody>
        </table>
    </div>
    @else
        <p class="text-muted">No orders with payment tracking enabled.</p>
    @endif
</div>
@endsection
