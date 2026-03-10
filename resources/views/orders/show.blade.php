@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders / View')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Order #{{ $order->order_id }}</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
            <a class="btn" href="{{ route('orders.edit', $order->order_id) }}">Edit</a>
        </div>
    </div>

    {{-- Order Meta Bar --}}
    <div class="meta-bar">
        <div class="meta-item">
            <span class="meta-label">Source:</span>
            @if($order->marketplace_source === 'lazada')
                <span class="badge badge-indigo">Lazada</span>
            @elseif($order->marketplace_source === 'shopee')
                <span class="badge badge-dark">Shopee</span>
            @elseif($order->marketplace_source !== '')
                <span class="badge badge-dark">{{ ucfirst($order->marketplace_source) }}</span>
            @else
                <span class="badge badge-default">Manual</span>
            @endif
        </div>
        <div class="meta-item">
            <span class="meta-label">Status:</span>
            <span class="badge badge-blue">{{ $order->status->name ?? '-' }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Date:</span>
            {{ $order->date_added }}
        </div>
        @if($order->marketplace_order_id)
        <div class="meta-item">
            <span class="meta-label">Marketplace ID:</span>
            @if($order->marketplace_source === 'lazada')
                <a href="https://sellercenter.lazada.com.ph/order/detail?tradeOrderId={{ $order->marketplace_order_id }}" target="_blank" rel="noopener" style="color:var(--accent);">
                    {{ $order->marketplace_order_id }} &#8599;
                </a>
            @elseif($order->marketplace_source === 'shopee')
                <a href="https://seller.shopee.ph/portal/sale/{{ $order->marketplace_order_id }}" target="_blank" rel="noopener" style="color:var(--accent);">
                    {{ $order->marketplace_order_id }} &#8599;
                </a>
            @else
                {{ $order->marketplace_order_id }}
            @endif
        </div>
        @endif
        @if($order->tracking_number)
        <div class="meta-item">
            <span class="meta-label">Tracking:</span>
            <a href="https://parcelsapp.com/en/tracking/{{ $order->tracking_number }}" target="_blank" rel="noopener" style="color:var(--accent);">
                {{ $order->tracking_number }} &#8599;
            </a>
        </div>
        @endif
    </div>

    {{-- Customer + Addresses --}}
    <div class="detail-grid">
        <div class="detail-section">
            <h4>Customer Details</h4>
            <div class="detail-body">
                <strong>{{ $order->firstname }} {{ $order->lastname }}</strong><br>
                @if($order->email)<span>{{ $order->email }}</span><br>@endif
                @if($order->telephone)<span>{{ $order->telephone }}</span><br>@endif
            </div>
        </div>

        <div class="detail-section">
            <h4>Payment Address</h4>
            <div class="detail-body">
                @if($order->payment_firstname || $order->payment_lastname)
                    <strong>{{ $order->payment_firstname }} {{ $order->payment_lastname }}</strong><br>
                @endif
                @if($order->payment_company)<span>{{ $order->payment_company }}</span><br>@endif
                @if($order->payment_address_1)<span>{{ $order->payment_address_1 }}</span><br>@endif
                @if($order->payment_address_2)<span>{{ $order->payment_address_2 }}</span><br>@endif
                @if($order->payment_city || $order->payment_postcode)
                    <span>{{ $order->payment_city }} {{ $order->payment_postcode }}</span><br>
                @endif
                @if($order->payment_zone)<span>{{ $order->payment_zone }}</span><br>@endif
                @if($order->payment_country)<span>{{ $order->payment_country }}</span>@endif
                @if($order->payment_method)
                    <br><br><span class="meta-label">Payment Method:</span> {{ $order->payment_method }}
                @endif
            </div>
        </div>

        <div class="detail-section">
            <h4>Shipping Address</h4>
            <div class="detail-body">
                @if($order->shipping_firstname || $order->shipping_lastname)
                    <strong>{{ $order->shipping_firstname }} {{ $order->shipping_lastname }}</strong><br>
                @endif
                @if($order->shipping_company)<span>{{ $order->shipping_company }}</span><br>@endif
                @if($order->shipping_address_1)<span>{{ $order->shipping_address_1 }}</span><br>@endif
                @if($order->shipping_address_2)<span>{{ $order->shipping_address_2 }}</span><br>@endif
                @if($order->shipping_city || $order->shipping_postcode)
                    <span>{{ $order->shipping_city }} {{ $order->shipping_postcode }}</span><br>
                @endif
                @if($order->shipping_zone)<span>{{ $order->shipping_zone }}</span><br>@endif
                @if($order->shipping_country)<span>{{ $order->shipping_country }}</span>@endif
                @if($order->shipping_method)
                    <br><br><span class="meta-label">Shipping Method:</span> {{ $order->shipping_method }}
                @endif
            </div>
        </div>

        @if($order->comment)
        <div class="detail-section">
            <h4>Order Comment</h4>
            <div class="detail-body">{{ $order->comment }}</div>
        </div>
        @endif
    </div>

    {{-- Products --}}
    <h3 class="section-title">Products</h3>
    @if($products->count())
    @php
        $totalRevenue = 0;
        $totalCogs = 0;
    @endphp
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:60px;">Image</th>
            <th>Product</th>
            <th>Model / SKU</th>
            <th class="text-right">Qty</th>
            <th class="text-right">Unit Price</th>
            <th class="text-right">Total</th>
            <th class="text-right">Unit Cost</th>
            <th class="text-right">COGS</th>
            <th class="text-right">Profit</th>
            <th class="text-right">Profit Margin %</th>
            <th class="text-right">Profit Markup %</th>
        </tr>
        </thead>
        <tbody>
        @foreach($products as $p)
            @php
                $catalogProduct = $catalogProducts[$p->order_product_id] ?? null;
                $img = $catalogProduct ? trim((string)($catalogProduct->image ?? '')) : '';
                $imgSrc = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                if ($imgSrc === '') {
                    $lzImg = trim($lazadaImages[$p->order_product_id] ?? '');
                    if ($lzImg !== '') { $imgSrc = $lzImg; }
                }

                $unitCost = (float) ($p->cost ?? 0);
                $cogs = $unitCost * (int) $p->quantity;
                $lineTotal = (float) $p->total;
                $profit = $lineTotal - $cogs;
                $margin = $lineTotal > 0 ? ($profit / $lineTotal * 100) : 0;
                $markup = $cogs > 0 ? ($profit / $cogs * 100) : 0;

                $totalRevenue += $lineTotal;
                $totalCogs += $cogs;
            @endphp
            <tr>
                <td>
                    @if($imgSrc)
                        <img src="{{ $imgSrc }}" alt="" class="thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span class="thumb-placeholder" style="width:48px;height:48px;"></span>
                    @endif
                </td>
                <td>
                    @if($catalogProduct)
                        <a href="{{ route('products.edit', $catalogProduct->product_id) }}" class="font-semibold" style="color:var(--accent);">{{ $p->name }}</a>
                    @else
                        <div class="font-semibold">{{ $p->name }}</div>
                    @endif
                    @if($p->options->count())
                        @foreach($p->options as $opt)
                            <small class="text-secondary">{{ $opt->name }}: {{ $opt->value }}</small><br>
                        @endforeach
                    @endif
                </td>
                <td class="text-sm text-secondary">{{ $p->model }}</td>
                <td class="text-right">{{ $p->quantity }}</td>
                <td class="text-right">{{ number_format($p->price, 2) }}</td>
                <td class="text-right font-semibold">{{ number_format($p->total, 2) }}</td>
                <td class="text-right text-secondary">{{ $unitCost > 0 ? number_format($unitCost, 2) : '-' }}</td>
                <td class="text-right text-secondary">{{ $unitCost > 0 ? number_format($cogs, 2) : '-' }}</td>
                <td class="text-right font-semibold" style="color: {{ $profit >= 0 ? 'var(--success)' : 'var(--danger)' }}">
                    {{ $unitCost > 0 ? number_format($profit, 2) : '-' }}
                </td>
                <td class="text-right">
                    @if($unitCost > 0)
                        <span style="color: {{ $margin >= 20 ? 'var(--success)' : ($margin >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ number_format($margin, 1) }}%</span>
                    @else
                        -
                    @endif
                </td>
                <td class="text-right">
                    @if($unitCost > 0)
                        <span style="color: {{ $markup >= 20 ? 'var(--success)' : ($markup >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ number_format($markup, 1) }}%</span>
                    @else
                        -
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @else
        <p class="text-muted">No products on this order.</p>
    @endif

    {{-- Order Totals + Cost & Profit Summary --}}
    @php
        $mktFeeTotal = (float) ($marketplaceFees['total'] ?? 0);
        $mktFeeItems = $marketplaceFees['items'] ?? [];
        $mktFeeSource = $marketplaceFees['source'] ?? '';
        $shippingCost = (float) ($order->shipping_cost ?? 0);
        $grossProfit = $totalRevenue - $totalCogs;
        $netProfit = $grossProfit - $shippingCost - $mktFeeTotal;
        $netMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue * 100) : 0;
        $grossMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue * 100) : 0;
        $netMarkup = ($totalCogs + $shippingCost + $mktFeeTotal) > 0 ? ($netProfit / ($totalCogs + $shippingCost + $mktFeeTotal) * 100) : 0;
        $grossMarkup = $totalCogs > 0 ? ($grossProfit / $totalCogs * 100) : 0;
        $hasCostData = $totalCogs > 0;
        $hasDeductions = $shippingCost > 0 || $mktFeeTotal > 0;
        $hasAnyProfit = $hasCostData || $hasDeductions;
    @endphp
    <div class="d-flex justify-end mt-16">
        <table class="order-totals-table">
            <tbody>
            {{-- Standard order totals (Sub-Total, Shipping, etc.) --}}
            @foreach($orderTotals as $ot)
                @if($ot->code !== 'total')
                <tr>
                    <td class="text-secondary">{{ $ot->title }}</td>
                    <td class="text-right">{{ number_format($ot->value, 2) }}</td>
                </tr>
                @endif
            @endforeach

            {{-- Order Total --}}
            <tr class="order-totals-row-total">
                <td>Total</td>
                <td class="text-right">{{ number_format($order->total, 2) }}</td>
            </tr>

            @if($hasAnyProfit)
            {{-- Separator --}}
            <tr><td colspan="2" style="padding:0;"><hr style="border:none; border-top:1px dashed var(--border); margin:8px 0;"></td></tr>

            {{-- COGS --}}
            @if($hasCostData)
            <tr>
                <td class="text-secondary">COGS</td>
                <td class="text-right" style="color:var(--danger);">-{{ number_format($totalCogs, 2) }}</td>
            </tr>
            @endif

            {{-- Shipping Cost --}}
            @if($shippingCost > 0)
            <tr>
                <td class="text-secondary">Shipping Cost</td>
                <td class="text-right" style="color:var(--danger);">-{{ number_format($shippingCost, 2) }}</td>
            </tr>
            @endif

            {{-- Marketplace Fees (itemized) --}}
            @foreach($mktFeeItems as $fee)
            <tr>
                <td class="text-secondary">{{ $fee['label'] }}</td>
                <td class="text-right" style="color:var(--danger);">-{{ number_format($fee['amount'], 2) }}</td>
            </tr>
            @endforeach

            {{-- Net Profit --}}
            <tr class="order-totals-row-profit">
                <td>
                    {{ $hasDeductions ? 'Net Profit' : 'Gross Profit' }}
                    @if($hasAnyProfit)
                    @php $displayMargin = $hasDeductions ? $netMargin : $grossMargin; $displayMarkup = $hasDeductions ? $netMarkup : $grossMarkup; @endphp
                    <span class="order-totals-margin" style="color: {{ $displayMargin >= 20 ? 'var(--success)' : ($displayMargin >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ number_format($displayMargin, 1) }}%</span>
                    <span class="text-xs text-secondary" style="margin-left:4px;">Markup:</span>
                    <span class="order-totals-margin" style="color: {{ $displayMarkup >= 20 ? 'var(--success)' : ($displayMarkup >= 10 ? 'var(--warning, #e6a700)' : 'var(--danger)') }}">{{ number_format($displayMarkup, 1) }}%</span>
                    @endif
                </td>
                <td class="text-right" style="color: {{ ($hasDeductions ? $netProfit : $grossProfit) >= 0 ? 'var(--success)' : 'var(--danger)' }}">
                    {{ number_format($hasDeductions ? $netProfit : $grossProfit, 2) }}
                </td>
            </tr>
            @endif
            </tbody>
        </table>
    </div>

    {{-- Payments --}}
    @php
        $payments = $payments ?? collect();
        $totalPaid = (float) ($totalPaid ?? 0);
        $orderTotal = (float) $order->total;
        $balance = $orderTotal - $totalPaid;
    @endphp

    @if($order->track_payments)
    <div class="d-flex items-center justify-between mt-24 mb-12" style="padding-bottom:8px; border-bottom:2px solid var(--border);">
        <h3 style="margin:0; font-size:15px; font-weight:600;">
            Payments
            @if($balance <= 0 && $orderTotal > 0)
                <span class="badge badge-green" style="font-size:11px; margin-left:8px;">Paid in Full</span>
            @elseif($totalPaid > 0)
                <span class="badge badge-yellow" style="font-size:11px; margin-left:8px;">Balance: {{ number_format($balance, 2) }}</span>
            @endif
        </h3>
        <form method="POST" action="{{ route('orders.toggle_payments', $order->order_id) }}" data-confirm="Disable payment tracking for this order?">
            @csrf
            <button type="submit" class="btn small secondary">Disable Tracking</button>
        </form>
    </div>

    @if($payments->count())
    <div class="table-wrap mb-12">
        <table class="table" style="width:100%;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Method</th>
                <th>Reference</th>
                <th class="text-right">Amount</th>
                <th>Notes</th>
                <th style="width:40px;"></th>
            </tr>
            </thead>
            <tbody>
            @foreach($payments as $p)
            <tr>
                <td>{{ $p->paid_at->format('M d, Y') }}</td>
                <td>{{ $p->payment_method }}</td>
                <td class="text-muted">{{ $p->reference_no ?: '—' }}</td>
                <td class="text-right font-semibold">{{ number_format((float) $p->amount, 2) }}</td>
                <td class="text-muted text-sm">{{ $p->notes ?: '' }}</td>
                <td>
                    <form method="POST" action="{{ route('orders.destroy_payment', [$order->order_id, $p->id]) }}" data-confirm="Delete this payment?">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn small danger" title="Delete">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
            @endforeach
            <tr style="border-top:2px solid var(--border);">
                <td colspan="3" class="text-right font-semibold">Total Paid</td>
                <td class="text-right font-semibold">{{ number_format($totalPaid, 2) }}</td>
                <td colspan="2"></td>
            </tr>
            @if($balance > 0)
            <tr>
                <td colspan="3" class="text-right font-semibold" style="color:var(--warning);">Remaining Balance</td>
                <td class="text-right font-semibold" style="color:var(--warning);">{{ number_format($balance, 2) }}</td>
                <td colspan="2"></td>
            </tr>
            @endif
            </tbody>
        </table>
    </div>
    @elseif($orderTotal > 0)
        <p class="text-muted mb-12">No payments recorded.</p>
    @endif

    {{-- Add Payment Form --}}
    <form method="POST" action="{{ route('orders.store_payment', $order->order_id) }}" class="mb-24">
        @csrf
        <div class="d-flex gap-12 items-end flex-wrap">
            <div>
                <label class="font-semibold">Date</label>
                <input type="date" name="paid_at" class="input" value="{{ date('Y-m-d') }}" style="min-width:140px;">
            </div>
            <div>
                <label class="font-semibold">Amount</label>
                <input type="number" name="amount" class="input" step="0.01" min="0.01" placeholder="0.00" style="min-width:120px;" {{ $balance > 0 ? 'value=' . number_format($balance, 2, '.', '') : '' }}>
            </div>
            <div>
                <label class="font-semibold">Method</label>
                <select name="payment_method" class="input" style="min-width:140px;">
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="GCash">GCash</option>
                    <option value="Cash">Cash</option>
                    <option value="Check">Check</option>
                    <option value="Maya">Maya</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div>
                <label class="font-semibold">Reference #</label>
                <input type="text" name="reference_no" class="input" placeholder="Transaction/check #" style="min-width:140px;">
            </div>
            <div style="flex:1; min-width:140px;">
                <label class="font-semibold">Notes</label>
                <input type="text" name="notes" class="input" placeholder="Optional">
            </div>
            <div>
                <button class="btn" type="submit">Add Payment</button>
            </div>
        </div>
    </form>
    @else
    {{-- Payment tracking disabled — show enable button --}}
    <div class="mt-24 mb-24" style="display:flex; align-items:center; gap:12px;">
        <form method="POST" action="{{ route('orders.toggle_payments', $order->order_id) }}">
            @csrf
            <button type="submit" class="btn small">Enable Payment Tracking</button>
        </form>
        <span class="text-muted text-sm">Track partial payments for this order</span>
    </div>
    @endif

    {{-- Status Update --}}
    <h3 class="section-title mt-24">Update Status</h3>
    <form method="POST" action="{{ route('orders.update_status', $order->order_id) }}">
        @csrf
        <div class="d-flex gap-12 items-center flex-wrap">
            <div>
                <label class="font-semibold">Order Status</label>
                <select class="input" name="order_status_id" style="min-width:200px;">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ $order->order_status_id == $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1; min-width:200px;">
                <label class="font-semibold">Comment</label>
                <input class="input" name="comment" value="" placeholder="Optional note">
            </div>
            <div style="padding-top:22px;">
                <button class="btn" type="submit">Update Status</button>
            </div>
        </div>
    </form>

    {{-- Order History --}}
    <h3 class="section-title mt-24">Order History</h3>
    @if($history->count())
    <div>
        @foreach($history as $h)
        <div class="history-item">
            <div class="history-date">{{ $h->date_added }}</div>
            <div>
                <div class="history-status">{{ $h->status->name ?? '-' }}</div>
                @if($h->user_name)
                    <div class="text-xs text-secondary">by {{ $h->user_name }}</div>
                @endif
                @if($h->comment)
                    <div class="history-comment">{{ $h->comment }}</div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @else
        <p class="text-muted">No history records.</p>
    @endif
</div>
@endsection
