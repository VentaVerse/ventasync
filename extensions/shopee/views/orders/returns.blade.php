@extends('layouts.channel')
@section('breadcrumb', 'Return or Refund')

@section('content')
@php
    $canManageShopeeOrders = true;
@endphp
<div class="marketplace-shopee">
<div class="page-header">
    <h2>Shopee Return or Refund <span class="text-secondary text-sm">({{ $returns->total() }})</span></h2>
</div>

@if($canManageShopeeOrders)
<div class="card mb-16">
    <form method="POST" action="{{ route('ext.shopee.orders.fetch_returns') }}" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Fetch Returns</button>
        </div>
        <div class="text-secondary text-xs" style="flex:1;">
            Fetch return/refund records from Shopee API.
        </div>
    </form>
</div>
@endif

@if($last_result)
    <div class="alert {{ $last_result['ok'] ? 'success' : 'danger' }}">
        <strong>{{ $last_result['ok'] ? 'OK' : 'Error' }}</strong>
        @if(!empty($last_result['message']))
            <div style="margin-top:6px;">{{ $last_result['message'] }}</div>
        @endif
    </div>
@endif

{{-- Order tabs (mirrors orders page, with Return/Refund/Cancel active) --}}
<div class="tabs mb-12">
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'ALL']) }}" class="tab">All</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'UNPAID']) }}" class="tab">Unpaid</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'PENDING']) }}" class="tab">To Ship</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'SHIPPING']) }}" class="tab">Shipping</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'DELIVERED_COMPLETED']) }}" class="tab">Delivered</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'CANCELLED']) }}" class="tab">Cancelled</a>
    <a href="{{ route('ext.shopee.orders.index', ['tab' => 'FAILED_DELIVERY']) }}" class="tab">Failed Delivery</a>
    <a href="{{ route('ext.shopee.orders.returns') }}" class="tab active">Return/Refund/Cancel</a>
</div>

@php
    $tabs = $tabs ?? [];
    $active_tab = $active_tab ?? 'ALL';
    $tab_counts = $tab_counts ?? [];
@endphp

{{-- Status tabs --}}
<div class="tabs mb-12">
    @foreach($tabs as $key => $label)
        @php
            $isActive = strtoupper((string)$active_tab) === strtoupper((string)$key);
            $count = $tab_counts[$key] ?? null;
            $qs = array_merge(request()->query(), ['tab' => $key]);
            unset($qs['page']);
        @endphp
        <a href="{{ route('ext.shopee.orders.returns', $qs) }}" class="tab {{ $isActive ? 'active' : '' }}">
            <span>{{ $label }}</span>
            @if($count !== null && $count > 0)
                <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 6px; border-radius:999px; font-weight:700; font-size:12px; {{ $isActive ? 'background:#fff; color:#EE4D2D;' : 'background:#EE4D2D; color:#fff;' }}">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</div>

<div class="card mb-12" style="padding:12px 16px;">
    <form method="GET" action="{{ route('ext.shopee.orders.returns') }}" class="d-flex gap-10 flex-wrap items-center">
        <input type="hidden" name="per_page" value="{{ (int)($per_page ?? 10) }}">
        <input type="hidden" name="tab" value="{{ $active_tab }}">
        <div>
            <label class="text-xs text-secondary">Return SN</label>
            <input type="text" name="return_sn" value="{{ $filters['return_sn'] ?? '' }}" placeholder="Return SN" class="input" style="min-width:200px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Order SN</label>
            <input type="text" name="order_sn" value="{{ $filters['order_sn'] ?? '' }}" placeholder="Order SN" class="input" style="min-width:200px;">
        </div>
        <div style="align-self:flex-end;">
            <button type="submit" class="btn">Search</button>
        </div>
        <div style="align-self:flex-end;">
            <a href="{{ route('ext.shopee.orders.returns', ['per_page' => (int)($per_page ?? 10), 'tab' => $active_tab]) }}" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    @if($returns->count() === 0)
        <div style="padding:40px 20px; text-align:center;">
            <div class="text-secondary">No return/refund records found.</div>
            @if(($filters['return_sn'] ?? '') !== '' || ($filters['order_sn'] ?? '') !== '')
                <div class="text-xs text-muted" style="margin-top:8px;">Try adjusting your search filters.</div>
            @else
                <div class="text-xs text-muted" style="margin-top:8px;">Click "Fetch Returns" to pull data from Shopee.</div>
            @endif
        </div>
    @else
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width:300px;">Returned Products</th>
                    <th style="width:110px;">Qty Returned</th>
                    <th style="width:130px;">Refund</th>
                    <th style="width:140px;">Status</th>
                    <th style="width:150px;">Return Tracking</th>
                    <th style="width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($returns as $ret)
                    @php
                        $cur = $ret->currency ?: \App\Support\Money::defaultSymbol();
                        $items = is_array($ret->items) ? $ret->items : [];
                        $lineCount = max(1, count($items));

                        // ordered-qty map (item_id_model_id => qty) from linked order
                        $orderedMap = [];
                        if ($ret->order && $ret->order->relationLoaded('products')) {
                            foreach ($ret->order->products as $p) {
                                $k = ((string)$p->item_id) . '_' . ((string)$p->model_id);
                                $orderedMap[$k] = ($orderedMap[$k] ?? 0) + (int) $p->quantity;
                            }
                        }

                        $st = strtolower($ret->status ?? '');
                        $badge = 'ret-badge-slate';
                        foreach ([
                            'requested'=>'ret-badge-amber','processing'=>'ret-badge-amber','accepted'=>'ret-badge-amber',
                            'judging'=>'ret-badge-pink','dispute'=>'ret-badge-pink',
                            'refund'=>'ret-badge-green','compensation'=>'ret-badge-green','cancel'=>'ret-badge-red',
                        ] as $k=>$cls) { if (str_contains($st,$k)) { $badge=$cls; break; } }

                        $detailUrl = route('ext.shopee.orders.return_detail', ['returnSn' => $ret->return_sn]);
                        $rawRet = is_array($ret->raw) ? $ret->raw : [];
                        $buyer = (string)($rawRet['user']['username'] ?? '');
                        $created = $ret->return_created_at ? \Carbon\Carbon::parse($ret->return_created_at)->format('M d, Y H:i') : '';
                    @endphp

                    {{-- Group header: buyer + order# + return# --}}
                    <tr class="order-header-row">
                        <td colspan="6" style="padding:10px 12px 6px 12px;">
                            <div class="d-flex justify-between items-start gap-12">
                                <div class="text-xs text-secondary" style="line-height:1.35;">
                                    @if($ret->order?->catalog_order_id)
                                        <div style="font-weight:700; margin-bottom:2px;"><a href="{{ url('/sales/orders/'.$ret->order->catalog_order_id) }}" style="color:var(--text-primary); text-decoration:none;">{{ $ret->order->catalog_order_id }}</a></div>
                                    @endif
                                    <span>Buyer:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $buyer !== '' ? $buyer : '—' }}</span>
                                    <span style="margin-left:8px;">Order SN:</span>
                                    @if($ret->order_sn)
                                        <a href="{{ route('ext.shopee.orders.show', ['orderSn' => $ret->order_sn]) }}" class="font-semibold" style="color:var(--accent);">{{ $ret->order_sn }}</a>
                                    @else
                                        <span class="font-semibold" style="color:var(--text-primary);">—</span>
                                    @endif
                                    <span style="margin-left:8px;">Return SN:</span>
                                    <a href="{{ $detailUrl }}" class="font-semibold" style="color:var(--text-primary);">{{ $ret->return_sn }}</a>
                                </div>
                                <div class="text-xs text-secondary text-right" style="line-height:1.35;">
                                    @if($created)<span>Requested:</span> <span class="font-semibold" style="color:var(--text-primary);">{{ $created }}</span>@endif
                                </div>
                            </div>
                        </td>
                    </tr>

                    @foreach(($items ?: [[]]) as $idx => $it)
                        @php
                            $name = (string)($it['name'] ?? '');
                            $sku  = (string)($it['item_sku'] ?? '');
                            $opt  = (string)($it['variation_sku'] ?? '');
                            $img  = (string)($it['images'][0] ?? '');
                            $retQty = (int)($it['amount'] ?? 0);
                            $key = ((string)($it['item_id'] ?? '')) . '_' . ((string)($it['model_id'] ?? ''));
                            $ordered = $orderedMap[$key] ?? null;
                        @endphp
                        <tr>
                            <td style="vertical-align:top;">
                                <div class="d-flex gap-10">
                                    <div class="order-img-wrap">
                                        @if($img)<img class="order-img" src="{{ $img }}" alt="">@else<span class="text-xs text-muted">No image</span>@endif
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="font-semibold" style="word-break:break-word;">
                                            <a href="{{ $detailUrl }}" style="color:var(--text-primary); text-decoration:underline; text-underline-offset:2px;">{{ $name !== '' ? $name : '—' }}</a>
                                        </div>
                                        <div class="text-xs" style="margin-top:3px;"><span class="text-secondary">SKU:</span> <strong>{{ $sku !== '' ? $sku : '—' }}</strong></div>
                                        @if($opt !== '')
                                            <div class="order-option-wrap">
                                                <span class="order-option-label shopee-label">Option</span>
                                                <span class="order-option-pill">{{ $opt }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td style="vertical-align:top;">
                                <div class="font-bold">x{{ $retQty }}</div>
                                @if($ordered !== null)<div class="text-xs text-muted">of {{ $ordered }} ordered</div>@endif
                            </td>
                            <td style="vertical-align:top;">
                                @if(($it['refund_amount'] ?? null) !== null)
                                    <strong style="color:var(--danger,#dc2626);">{{ $cur . number_format((float)$it['refund_amount'], 2) }}</strong>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            @if($idx === 0)
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    <span class="ret-badge {{ $badge }}">{{ $ret->status ?? '—' }}</span>
                                    <div class="text-xs text-muted" style="margin-top:6px;">{{ \Illuminate\Support\Str::limit($ret->reason ?? '', 40) }}</div>
                                    <div class="text-xs text-secondary" style="margin-top:4px;">{{ $ret->return_updated_at ? \Carbon\Carbon::parse($ret->return_updated_at)->format('M d, H:i') : '' }}</div>
                                </td>
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    @if($ret->tracking_number)
                                        <div class="font-semibold text-sm" style="word-break:break-all;">{{ $ret->tracking_number }}</div>
                                    @else
                                        <span class="text-muted text-xs">—</span>
                                    @endif
                                    @if($ret->reverse_logistics_status)
                                        <div class="text-xs text-muted" style="margin-top:4px;">{{ str_replace('_',' ',$ret->reverse_logistics_status) }}</div>
                                    @endif
                                </td>
                                <td rowspan="{{ $lineCount }}" style="vertical-align:top;">
                                    <div class="d-flex gap-6 flex-wrap">
                                        <a href="{{ $detailUrl }}" class="btn small">View Detail</a>
                                        @if($ret->order_sn)
                                            <a href="{{ route('ext.shopee.orders.awb', ['orderSn' => $ret->order_sn]) }}" onclick="window.open(this.href, 'awb', 'width=900,height=700'); return false;" class="btn small secondary" title="Print the original order's air waybill">Print AWB</a>
                                        @endif
                                    </div>
                                    <div class="text-xs text-secondary" style="margin-top:6px;">Total: <strong>{{ $cur . number_format((float)$ret->refund_amount, 2) }}</strong></div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:12px 0 0;">{{ $returns->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    @endif
</div>

{{-- Per-page selector --}}
<form method="GET" action="{{ route('ext.shopee.orders.returns') }}" id="formPerPage" style="margin-top:12px;">
    <input type="hidden" name="return_sn" value="{{ $filters['return_sn'] ?? '' }}">
    <input type="hidden" name="order_sn" value="{{ $filters['order_sn'] ?? '' }}">
    <input type="hidden" name="tab" value="{{ $active_tab }}">
    <div class="d-flex items-center gap-8">
        <label class="text-xs text-secondary">Per page:</label>
        <select name="per_page" class="input" style="width:auto; height:32px; font-size:13px;" onchange="this.form.submit()">
            @foreach([10,20,50] as $pp)
                <option value="{{ $pp }}" {{ (int)$per_page === $pp ? 'selected' : '' }}>{{ $pp }}</option>
            @endforeach
        </select>
    </div>
</form>
</div>{{-- /.marketplace-shopee --}}
@endsection
