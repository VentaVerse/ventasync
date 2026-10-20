<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice ? $invoice->label() : 'Order ' . $order->order_id }}</title>
    <style>
        @page { margin: 48px 48px 40px; }
        body, div, p, table, tr, td, th, img { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #0b1228; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #4a5468; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .logo { max-height: 48px; max-width: 180px; margin-bottom: 8px; }
        .seller-name { font-size: 13px; font-weight: bold; }
        .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 2px; text-align: right; }
        .doc-no { font-size: 11px; text-align: right; margin-top: 2px; }
        .doc-meta { text-align: right; margin-top: 4px; }
        .blocks { margin-top: 22px; border-top: 1.5px solid #0b1228; border-bottom: 1px solid #c9d0db; }
        .blocks > tbody > tr > td { width: 50%; padding: 10px 0; }
        .blocks > tbody > tr > td + td { padding-left: 18px; border-left: 1px solid #c9d0db; }
        .label { font-size: 7.5px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #4a5468; margin-bottom: 3px; }
        .kv td { padding: 1px 0; }
        .kv td.k { width: 78px; color: #4a5468; }
        .items { margin-top: 18px; }
        .items th { font-size: 7.5px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #4a5468; text-align: left; padding: 6px; background: #f1f4f8; border-bottom: 1px solid #c9d0db; }
        .items td { padding: 7px 6px; border-bottom: 1px solid #c9d0db; }
        .num { text-align: right; white-space: nowrap; }
        .n { width: 18px; color: #4a5468; }
        .item-name { font-weight: bold; }
        .item-sub { color: #4a5468; font-size: 8.5px; }
        .sum { margin-top: 14px; }
        .sum > tbody > tr > td { width: 50%; }
        .vat-cell { padding-right: 18px; }
        .vat { border: 1px solid #c9d0db; }
        .vat td, .totals td { padding: 3px 6px; }
        .vat td.v, .totals td.v { text-align: right; white-space: nowrap; }
        .totals td.k { color: #4a5468; }
        .totals tr.due td { border-top: 1.5px solid #0b1228; padding-top: 6px; font-size: 11.5px; font-weight: bold; color: #0b1228; }
        .not-valid { margin-top: 22px; text-align: center; font-weight: bold; letter-spacing: 1px; font-size: 9px; padding: 6px; border: 1px solid #0b1228; }
    </style>
</head>
<body>
@php
    $money = fn (float $v) => \App\Support\Money::base($v);
    $lineTotals = array_values(array_filter($totals, fn ($t) => $t['code'] !== 'total'));
    $grand = collect($totals)->firstWhere('code', 'total');
@endphp

<table>
    <tr>
        <td>
            @if($seller['logo'])<img class="logo" src="{{ $seller['logo'] }}" alt="">@endif
            <div class="seller-name">{{ $seller['name'] }}</div>
            @if($seller['business_style'] !== '')<div class="muted">{{ $seller['business_style'] }}</div>@endif
            @if($seller['address'] !== '')<div class="muted">{{ $seller['address'] }}</div>@endif
            @if($seller['contact'] !== '')<div class="muted">{{ $seller['contact'] }}</div>@endif
            @if($seller['tin'] !== '')
                <div class="mono">{{ $seller['is_vat'] ? 'VAT Reg. TIN' : 'Non-VAT Reg. TIN' }} {{ $seller['tin'] }}</div>
            @endif
        </td>
        <td>
            <div class="doc-title">{{ $invoice ? 'INVOICE' : 'ORDER' }}</div>
            @if($invoice)
                <div class="doc-no mono">No. {{ $invoice->label() }}</div>
                <div class="doc-meta muted">Date issued <strong>{{ $invoice->issued_at->format('j M Y') }}</strong></div>
            @else
                <div class="doc-meta muted">Printed <strong>{{ now()->format('j M Y') }}</strong></div>
            @endif
        </td>
    </tr>
</table>

<table class="blocks">
    <tr>
        <td>
            <div class="label">Sold to</div>
            <div><strong>{{ $customer['name'] !== '' ? $customer['name'] : 'Guest' }}</strong></div>
            @if($customer['company'] !== '')<div>{{ $customer['company'] }}</div>@endif
            @if($customer['address'] !== '')<div class="muted">{{ $customer['address'] }}</div>@endif
            @if($customer['tin'] !== '')<div class="mono">TIN {{ $customer['tin'] }}</div>@endif
        </td>
        <td>
            <div class="label">Order</div>
            <table class="kv">
                <tr><td class="k">Order no.</td><td>#{{ $order->order_id }}</td></tr>
                @if($store !== '')<tr><td class="k">Store</td><td>{{ $store }}</td></tr>@endif
                @if(trim((string) $order->channel_order_id) !== '')<tr><td class="k">Store order</td><td class="mono">{{ $order->channel_order_id }}</td></tr>@endif
                @if($order->date_added)<tr><td class="k">Order date</td><td>{{ \Illuminate\Support\Carbon::parse($order->date_added)->format('j M Y') }}</td></tr>@endif
                @if(trim((string) $order->payment_method) !== '')<tr><td class="k">Payment</td><td>{{ $order->payment_method }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr><th class="n">#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr>
    </thead>
    <tbody>
        @foreach($lines as $i => $line)
            <tr>
                <td class="n">{{ $i + 1 }}</td>
                <td>
                    <div class="item-name">{{ $line['name'] }}</div>
                    @php $sub = implode(' · ', array_filter([$line['variation'], $line['sku']], fn ($p) => $p !== '')); @endphp
                    @if($sub !== '')<div class="item-sub">{{ $sub }}</div>@endif
                </td>
                <td class="num">{{ number_format($line['quantity']) }}</td>
                <td class="num">{{ $money($line['price']) }}</td>
                <td class="num">{{ $money($line['total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="sum">
    <tr>
        <td class="vat-cell">
            @if($tax)
                <table class="vat">
                    <tr><td colspan="2"><div class="label">VAT breakdown</div></td></tr>
                    <tr><td>VATable sales</td><td class="v">{{ $money($tax['taxable']) }}</td></tr>
                    <tr><td>{{ $tax['name'] }} ({{ $tax['percent'] }})</td><td class="v">{{ $money($tax['tax']) }}</td></tr>
                    <tr><td>VAT-exempt sales</td><td class="v">{{ $money(0) }}</td></tr>
                    <tr><td>Zero-rated sales</td><td class="v">{{ $money(0) }}</td></tr>
                </table>
            @endif
        </td>
        <td>
            <table class="totals">
                @foreach($lineTotals as $row)
                    <tr><td class="k">{{ $row['title'] }}</td><td class="v">{{ $money($row['value']) }}</td></tr>
                @endforeach
                <tr class="due"><td>Total amount due</td><td class="v">{{ $money($grand['value'] ?? (float) $order->total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@unless($seller['is_vat'])
    <div class="not-valid">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX</div>
@endunless
</body>
</html>
