@extends('layouts.app')

@section('content')
@php
    $raw = is_array($order->raw ?? null) ? $order->raw : [];
    $detail = is_array($detail ?? null) ? $detail : [];

    $status = $order->status ?? ($detail['statuses'] ?? $detail['status'] ?? $raw['statuses'] ?? $raw['status'] ?? '');
    if (is_array($status)) { $status = implode(', ', $status); }
    $status = is_string($status) ? $status : '';

    $created = $order->order_created_at ? $order->order_created_at->format('Y-m-d H:i') : ($detail['created_at'] ?? $raw['created_at'] ?? $raw['createdAt'] ?? $raw['created_time'] ?? null);
    $updated = $order->order_updated_at ? $order->order_updated_at->format('Y-m-d H:i') : ($detail['updated_at'] ?? $raw['updated_at'] ?? $raw['updatedAt'] ?? $raw['update_time'] ?? null);

    $buyer = (string)($detail['customer_name'] ?? $detail['customer_first_name'] ?? $detail['buyer_name'] ?? $raw['customer_name'] ?? $raw['customer_first_name'] ?? $raw['buyer_name'] ?? '');

    // Shipping / receiver fields (names differ by venture)
    $receiverName = (string)($detail['address_shipping']['first_name'] ?? $detail['address_shipping']['customer_name'] ?? $detail['receiver_name'] ?? $detail['shipping_address']['name'] ?? '');
    if ($receiverName === '') { $receiverName = $buyer; }

    $phone = (string)($detail['address_shipping']['phone'] ?? $detail['address_shipping']['phone2'] ?? $detail['receiver_phone'] ?? $detail['shipping_address']['phone'] ?? '');
    $address1 = (string)($detail['address_shipping']['address1'] ?? $detail['address_shipping']['address'] ?? $detail['shipping_address']['address1'] ?? $detail['shipping_address']['address'] ?? '');
    $address2 = (string)($detail['address_shipping']['address2'] ?? $detail['shipping_address']['address2'] ?? '');
    $city = (string)($detail['address_shipping']['city'] ?? $detail['shipping_address']['city'] ?? '');
    $province = (string)($detail['address_shipping']['province'] ?? $detail['shipping_address']['province'] ?? $detail['address_shipping']['state'] ?? $detail['shipping_address']['state'] ?? '');
    $postcode = (string)($detail['address_shipping']['post_code'] ?? $detail['address_shipping']['postcode'] ?? $detail['shipping_address']['post_code'] ?? $detail['shipping_address']['postcode'] ?? '');
    $country = (string)($detail['address_shipping']['country'] ?? $detail['shipping_address']['country'] ?? '');

    $courier = $detail['shipping_provider'] ?? $detail['shipping_provider_type'] ?? $detail['shipping_provider_name'] ?? $raw['shipping_provider'] ?? $raw['shipping_provider_type'] ?? $raw['shipping_provider_name'] ?? null;
    if (is_array($courier)) $courier = implode(', ', $courier);
    $courier = is_string($courier) ? trim($courier) : '';

    $tracking = $detail['tracking_code'] ?? $detail['tracking_number'] ?? $raw['tracking_code'] ?? $raw['tracking_number'] ?? null;
    if (is_array($tracking)) $tracking = implode(', ', $tracking);
    $tracking = is_string($tracking) ? trim($tracking) : '';

    $items = $order->products ?? collect();
@endphp

<div style="padding:16px;">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
        <div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <h2 style="margin:0;">Lazada Order</h2>
                <span style="font-size:12px; color:#64748b;">Order #</span>
                <span style="font-weight:800; color:#0f172a;">{{ $order->order_id }}</span>
                @if($status !== '')
                    <span style="margin-left:6px; font-size:12px; padding:4px 8px; border-radius:999px; border:1px solid #e5e7eb; background:#f8fafc; color:#0f172a;">{{ $status }}</span>
                @endif
            </div>
            <div style="margin-top:6px; font-size:12px; color:#64748b; line-height:1.5;">
                @if($created) <span>Create Time:</span> <span style="color:#334155; font-weight:600;">{{ $created }}</span> @endif
                @if($updated) <span style="margin-left:10px;">Update Time:</span> <span style="color:#334155; font-weight:600;">{{ $updated }}</span> @endif
            </div>
        </div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <a href="{{ route('lazada.orders.index') }}" class="btn" style="padding:10px 14px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#0f172a; text-decoration:none;">← Back to Orders</a>
            <a href="{{ route('lazada.orders.show', ['orderId' => $order->order_id, 'refresh' => 1]) }}" class="btn" style="padding:10px 14px; border-radius:8px; border:0; background:#2563eb; color:#fff; text-decoration:none;">Refresh from Lazada</a>
        </div>
    </div>

    @if($api_error)
        <div style="padding:10px 12px; border-radius:8px; margin-top:12px; background:#fef2f2; border:1px solid #fecaca;">
            <strong>Error</strong>
            <div style="margin-top:6px; color:#7f1d1d;">Unable to refresh order details from Lazada. Showing cached data.</div>
        </div>
    @endif

    @if($api_items_synced)
        <div style="padding:10px 12px; border-radius:8px; margin-top:12px; background:#ecfdf5; border:1px solid #a7f3d0;">
            <strong>OK</strong>
            <div style="margin-top:6px;">Order items refreshed.</div>
        </div>
    @endif

    <div style="display:grid; grid-template-columns: 1.1fr 0.9fr; gap:14px; margin-top:14px; align-items:start;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:800;">Customer & Shipping</div>
            <div style="padding:12px; font-size:13px; color:#0f172a; line-height:1.6;">
                <div><span style="color:#64748b;">Buyer:</span> <span style="font-weight:700;">{{ $buyer !== '' ? $buyer : '—' }}</span></div>
                <div style="margin-top:8px;"><span style="color:#64748b;">Receiver:</span> <span style="font-weight:700;">{{ $receiverName !== '' ? $receiverName : '—' }}</span></div>
                <div><span style="color:#64748b;">Phone:</span> <span style="font-weight:700;">{{ $phone !== '' ? $phone : '—' }}</span></div>

                <div style="margin-top:10px;">
                    <div style="color:#64748b; font-size:12px; font-weight:700;">Address</div>
                    <div style="margin-top:4px;">
                        {{ $address1 !== '' ? $address1 : '—' }}
                        @if($address2 !== '')<br>{{ $address2 }}@endif
                        @if($city !== '' || $province !== '' || $postcode !== '' || $country !== '')
                            <br>{{ trim($city . ' ' . $province) }} {{ $postcode }} {{ $country }}
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:800;">Logistics</div>
            <div style="padding:12px; font-size:13px; color:#0f172a; line-height:1.6;">
                <div><span style="color:#64748b;">Courier:</span> <span style="font-weight:700;">{{ $courier !== '' ? $courier : '—' }}</span></div>
                <div><span style="color:#64748b;">Tracking #:</span> <span style="font-weight:700;">{{ $tracking !== '' ? $tracking : '—' }}</span></div>
                <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="{{ route('lazada.orders.logistics_trace', ['orderId' => $order->order_id]) }}" target="_blank" rel="noopener" class="btn" style="padding:10px 12px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#0f172a; text-decoration:none;">View Logistics Trace</a>
                    <a href="{{ route('lazada.orders.awb', ['orderId' => $order->order_id]) }}" target="_blank" rel="noopener" class="btn" style="padding:10px 12px; border-radius:8px; border:0; background:#0f172a; color:#fff; text-decoration:none;">AWB PDF</a>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:800;">Items</div>
        @if($items->count() === 0)
            <div style="padding:12px; color:#64748b;">No items cached yet. Click <strong>Refresh from Lazada</strong>.</div>
        @else
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:90px;">Image</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb;">Product</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:130px;">SKU</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:100px;">Qty</th>
                        <th style="padding:10px 12px; border-bottom:1px solid #e5e7eb; width:190px;">Order Item ID</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $it)
                        @php
                            $ir = is_array($it->raw ?? null) ? $it->raw : [];
                            $img = (string)($it->image ?? ($ir['product_main_image'] ?? $ir['image'] ?? ''));
                            $name = (string)($it->name ?? ($ir['name'] ?? $ir['product_name'] ?? ''));
                            $sku = (string)($it->sku ?? ($ir['seller_sku'] ?? $ir['SellerSku'] ?? $ir['Sku'] ?? ''));
                            $variation = (string)($it->variation ?? ($ir['variation'] ?? ''));
                            $qty = (int)($it->quantity ?? ($ir['quantity'] ?? 1));
                            if ($qty < 1) $qty = 1;
                            $orderItemId = (string)($it->order_item_id ?? ($ir['order_item_id'] ?? $ir['orderItemId'] ?? ''));
                        @endphp
                        <tr>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                @if($img !== '')
                                    <img src="{{ $img }}" alt="" style="width:64px; height:64px; object-fit:cover; border:1px solid #e5e7eb; border-radius:8px;" onerror="this.style.display='none'">
                                @else
                                    <span style="color:#94a3b8;">—</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                                <div style="font-weight:700; color:#0f172a;">{{ $name !== '' ? $name : '—' }}</div>
                                @if($variation !== '')
                                    <div style="margin-top:4px; font-size:12px; color:#64748b;">{{ $variation }}</div>
                                @endif
                            </td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">{{ $sku !== '' ? $sku : '—' }}</td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">{{ $qty }}</td>
                            <td style="padding:10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">{{ $orderItemId !== '' ? $orderItemId : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div style="margin-top:14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:800;">Raw Payload (debug)</div>
        <div style="padding:12px;">
            <details>
                <summary style="cursor:pointer; color:#0f172a; font-weight:700;">Show raw JSON</summary>
                <pre style="margin-top:10px; white-space:pre-wrap; word-break:break-word; background:#0b1020; color:#e2e8f0; padding:12px; border-radius:10px; overflow:auto;">{{ json_encode(['order' => $raw, 'detail' => $detail], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        </div>
    </div>
</div>
@endsection
