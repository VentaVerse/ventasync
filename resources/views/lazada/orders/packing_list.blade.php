<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Packing List - {{ $orderId }}</title>
    <style>
        /* Print-only standalone page — styles are self-contained (not part of layout) */
        html, body { height: 100%; }
        body { font-family: Arial, sans-serif; color:#0f172a; margin:0; background:#e5e7eb; }
        .page {
            width: 8.5in;
            min-height: 11in;
            margin: 16px auto;
            background: #fff;
            padding: 0.5in;
            box-shadow: 0 4px 18px rgba(0,0,0,0.12);
            box-sizing: border-box;
        }
        .top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
        .text-muted { color:#64748b; font-size:12px; }
        h1 { font-size:20px; margin:0 0 4px 0; }
        .box { border:1px solid #e5e7eb; border-radius:10px; padding:12px; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { border:1px solid #e5e7eb; padding:8px; font-size:12px; text-align:left; vertical-align:top; }
        th { background:#f8fafc; }
        .actions { margin-top:12px; display:flex; gap:8px; }
        .btn { border:1px solid #e5e7eb; padding:8px 10px; border-radius:8px; background:#fff; cursor:pointer; font-size:12px; }
        .btn-primary { background:#0f172a; color:#fff; border-color:#0f172a; }
        @media print {
            @page { size: letter; margin: 0.5in; }
            html, body { background:#fff; }
            .actions { display:none; }
            .page { width:auto; min-height:auto; margin:0; padding:0; box-shadow:none; }
        }
    </style>
</head>
<body>
    <div class="page">
    <div class="top">
        <div>
            <h1>Packing List</h1>
            <div class="text-muted">Order #{{ $orderId }}</div>
        </div>
        <div class="box" style="min-width:260px;">
            <div class="font-bold" style="font-weight:700;">Summary</div>
            <div class="text-muted" style="margin-top:6px;">
                <div><strong>Created:</strong> {{ optional($order)->order_created_at ?? '-' }}</div>
                <div><strong>Buyer:</strong> {{ (string) (optional($order)->raw['customer_first_name'] ?? optional($order)->raw['customer_name'] ?? '-') }}</div>
                <div><strong>Items:</strong> {{ count($items) }}</div>
            </div>
        </div>
    </div>

    <div class="actions">
        <button class="btn btn-primary" onclick="window.print()">Print</button>
        <button class="btn" onclick="window.close()">Close</button>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:120px;">Order Item ID</th>
                <th style="width:150px;">SKU</th>
                <th>Product</th>
                <th style="width:80px;">Qty</th>
                <th style="width:140px;">Packed (tick)</th>
            </tr>
        </thead>
        <tbody>
        @forelse($items as $it)
            <tr>
                <td>{{ $it['order_item_id'] ?? '' }}</td>
                <td>{{ $it['sku'] ?? '' }}</td>
                <td>{{ $it['name'] ?? '' }}</td>
                <td style="text-align:center; font-weight:700;">{{ (int)($it['quantity'] ?? 0) }}</td>
                <td></td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="text-muted">No items found for this order.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <div class="text-muted" style="margin-top:12px;">Generated {{ now()->format('Y-m-d H:i') }}</div>
    </div>
</body>
</html>
