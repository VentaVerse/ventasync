@extends('layouts.app')
@section('breadcrumb', 'Integrations / Shopee / Return / ' . $return->return_sn)

@php
    use Illuminate\Support\Str;

    $cur = $return->currency ?: '₱';
    $fmtMoney = fn($v) => $cur . number_format((float) $v, 2);
    $fmtTs = function ($ts) {
        if (!$ts) return '—';
        try { return \Carbon\Carbon::createFromTimestamp((int) $ts)->format('M d, Y H:i'); }
        catch (\Throwable $e) { return '—'; }
    };

    $items       = is_array($raw['item'] ?? null) ? $raw['item'] : [];
    $buyerPhotos = is_array($raw['image'] ?? null) ? $raw['image'] : [];
    $buyerVideos = is_array($raw['buyer_videos'] ?? null) ? $raw['buyer_videos'] : [];
    $user        = is_array($raw['user'] ?? null) ? $raw['user'] : [];
    $negotiation = is_array($raw['negotiation'] ?? null) ? $raw['negotiation'] : [];
    $disputeReasons = is_array($raw['dispute_reason'] ?? null) ? $raw['dispute_reason'] : [];
    $disputeTexts   = is_array($raw['dispute_text_reason'] ?? null) ? $raw['dispute_text_reason'] : [];

    $st = strtoupper((string) $return->status);
    $needsLog = $return->needs_logistics;

    // Read-only recommendation (Phase 1)
    [$recLabel, $recTone] = match (true) {
        in_array($st, ['REQUESTED', 'PROCESSING', 'JUDGING', 'SELLER_DISPUTE']) => ['Return in progress — no action yet, just monitor.', 'info'],
        $st === 'ACCEPTED' && $needsLog => ['Goods are being returned. Verify the items on arrival, then restock and set the order to Returned.', 'action'],
        $st === 'ACCEPTED' && !$needsLog => ['Refund only — no goods coming back. Do not restock.', 'muted'],
        $st === 'REFUND_PAID' => ['Refund paid, no return. Do not restock.', 'muted'],
        $st === 'SELLER_COMPENSATION' => ['You were compensated — keep the order as sold.', 'ok'],
        in_array($st, ['CANCELLED', 'CLOSED']) => ['Return closed/cancelled — keep the order as sold.', 'ok'],
        default => ['—', 'muted'],
    };

    $statusStyle = function ($status) {
        $s = strtolower((string) $status);
        return match (true) {
            str_contains($s, 'requested') || str_contains($s, 'processing') => 'ret-badge-amber',
            str_contains($s, 'judging') || str_contains($s, 'dispute')       => 'ret-badge-pink',
            str_contains($s, 'refund') || str_contains($s, 'compensation')   => 'ret-badge-green',
            str_contains($s, 'accepted')                                     => 'ret-badge-amber',
            str_contains($s, 'cancel')                                       => 'ret-badge-red',
            default                                                          => 'ret-badge-slate',
        };
    };
@endphp

@section('content')
<div class="marketplace-shopee ret-detail">

    <div class="ret-head">
        <a href="{{ route('ext.shopee.orders.returns') }}" class="btn secondary small">← Back to Returns</a>
        <div class="ret-head-title">
            <h2>Return <span class="ret-sn">{{ $return->return_sn }}</span></h2>
            <span class="ret-badge {{ $statusStyle($return->status) }}">{{ $return->status ?: '—' }}</span>
            @if($needsLog === true)
                <span class="ret-badge ret-badge-blue">Goods returning</span>
            @elseif($needsLog === false)
                <span class="ret-badge ret-badge-slate">Refund only</span>
            @endif
        </div>
    </div>

    {{-- Recommendation banner (read-only guidance) --}}
    <div class="ret-rec ret-rec-{{ $recTone }}">
        <span class="ret-rec-ic">💡</span>
        <div>{{ $recLabel }}</div>
    </div>

    <div class="ret-grid">
        {{-- ============ MAIN COLUMN ============ --}}
        <div class="ret-main">

            {{-- Returned items --}}
            <div class="card">
                <div class="ret-card-title">Returned Items <span class="text-secondary text-sm">({{ count($items) }})</span></div>
                @forelse($items as $it)
                    @php
                        $img = $it['images'][0] ?? ($it['image_url'] ?? null);
                        $key = ((string)($it['item_id'] ?? '')) . '_' . ((string)($it['model_id'] ?? ''));
                        $ordered = $orderedQty[$key] ?? null;
                        $retQty  = (int) ($it['amount'] ?? 0);
                    @endphp
                    <div class="ret-item">
                        <div class="ret-item-thumb">
                            @if($img)<img src="{{ $img }}" alt="" loading="lazy">@else<div class="ret-item-noimg">No image</div>@endif
                        </div>
                        <div class="ret-item-body">
                            <div class="ret-item-name">{{ $it['name'] ?? 'Unknown item' }}</div>
                            <div class="ret-item-meta">
                                @if(!empty($it['item_sku']))<span>SKU: <strong>{{ $it['item_sku'] }}</strong></span>@endif
                                @if(!empty($it['variation_sku']))<span>Option: <strong>{{ $it['variation_sku'] }}</strong></span>@endif
                            </div>
                        </div>
                        <div class="ret-item-qty">
                            <div class="ret-qty-num">{{ $retQty }}@if($ordered !== null)<span class="ret-qty-of"> of {{ $ordered }}</span>@endif</div>
                            <div class="ret-qty-lbl">returned</div>
                        </div>
                        <div class="ret-item-refund">
                            <div class="ret-refund-num">{{ $fmtMoney($it['refund_amount'] ?? 0) }}</div>
                            <div class="ret-qty-lbl">@ {{ $fmtMoney($it['item_price'] ?? 0) }} each</div>
                        </div>
                    </div>
                @empty
                    <div class="text-muted" style="padding:8px 0;">No item detail on this return.</div>
                @endforelse

                <div class="ret-refund-total">
                    <span>Total refund</span>
                    <strong>{{ $fmtMoney($return->refund_amount) }}</strong>
                </div>
            </div>

            {{-- Reason + buyer evidence --}}
            <div class="card">
                <div class="ret-card-title">Buyer's Reason & Evidence</div>
                <div class="ret-reason">
                    <span class="ret-badge ret-badge-slate">{{ $return->reason ?: '—' }}</span>
                    @if(!empty($return->reason_text))
                        <p class="ret-reason-text">“{{ $return->reason_text }}”</p>
                    @endif
                </div>

                @if(count($buyerPhotos) || count($buyerVideos))
                    <div class="ret-media">
                        @foreach($buyerPhotos as $ph)
                            <a href="{{ $ph }}" target="_blank" rel="noopener" class="ret-media-item">
                                <img src="{{ $ph }}" alt="Buyer photo" loading="lazy">
                            </a>
                        @endforeach
                        @foreach($buyerVideos as $vid)
                            <a href="{{ $vid['video_url'] ?? '#' }}" target="_blank" rel="noopener" class="ret-media-item ret-media-video">
                                <img src="{{ $vid['thumbnail_url'] ?? '' }}" alt="Buyer video" loading="lazy">
                                <span class="ret-play">▶</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="text-muted text-sm" style="margin-top:8px;">No photos or videos uploaded by the buyer.</div>
                @endif
            </div>

            {{-- Dispute / negotiation --}}
            @if(!empty(array_filter($negotiation)) || count($disputeReasons))
            <div class="card">
                <div class="ret-card-title">Dispute & Negotiation</div>
                @if(!empty($negotiation['latest_solution']))
                    <div class="ret-kv"><span>Latest solution</span><strong>{{ str_replace('_', ' ', $negotiation['latest_solution']) }}</strong></div>
                @endif
                @if(($negotiation['latest_offer_amount'] ?? null) !== null)
                    <div class="ret-kv"><span>Latest offer</span><strong>{{ $fmtMoney($negotiation['latest_offer_amount']) }} <span class="text-secondary text-xs">by {{ $negotiation['latest_offer_creator'] ?? '—' }}</span></strong></div>
                @endif
                @if(($negotiation['counter_limit'] ?? null) !== null)
                    <div class="ret-kv"><span>Counter-offers left</span><strong>{{ $negotiation['counter_limit'] }}</strong></div>
                @endif
                @foreach($disputeReasons as $i => $dr)
                    <div class="ret-kv"><span>Dispute reason</span><strong>{{ $dr }}</strong></div>
                    @if(!empty($disputeTexts[$i]))<p class="ret-reason-text">“{{ $disputeTexts[$i] }}”</p>@endif
                @endforeach
            </div>
            @endif
        </div>

        {{-- ============ SIDEBAR ============ --}}
        <div class="ret-side">

            <div class="card">
                <div class="ret-card-title">Order & Buyer</div>
                <div class="ret-kv"><span>Order SN</span>
                    @if($return->order_sn)
                        <a href="{{ route('ext.shopee.orders.show', ['orderSn' => $return->order_sn]) }}">{{ $return->order_sn }}</a>
                    @else <strong>—</strong> @endif
                </div>
                <div class="ret-kv"><span>ERP order</span>
                    @if($return->order && $return->order->catalog_order_id)
                        <a href="{{ url('/sales/orders/' . $return->order->catalog_order_id) }}">#{{ $return->order->catalog_order_id }}</a>
                    @else <strong>—</strong> @endif
                </div>
                <div class="ret-kv"><span>Buyer</span><strong>{{ $user['username'] ?? '—' }}</strong></div>
                @if(!empty($user['email']))<div class="ret-kv"><span>Email</span><strong>{{ $user['email'] }}</strong></div>@endif
            </div>

            <div class="card">
                <div class="ret-card-title">Return Status</div>
                <div class="ret-kv"><span>Reverse logistics</span><strong>{{ $return->reverse_logistics_status ? str_replace('_', ' ', $return->reverse_logistics_status) : '—' }}</strong></div>
                <div class="ret-kv"><span>Tracking #</span><strong>{{ $return->tracking_number ?? ($raw['tracking_number'] ?? '—') ?: '—' }}</strong></div>
                <div class="ret-kv"><span>Needs return shipment</span><strong>{{ $needsLog === true ? 'Yes' : ($needsLog === false ? 'No' : '—') }}</strong></div>
                @if(!empty($raw['seller_compensation']['seller_compensation_status']))
                    <div class="ret-kv"><span>Seller compensation</span><strong>{{ str_replace('_', ' ', $raw['seller_compensation']['seller_compensation_status']) }}</strong></div>
                @endif
            </div>

            <div class="card">
                <div class="ret-card-title">Timeline</div>
                <div class="ret-kv"><span>Requested</span><strong>{{ $fmtTs($raw['create_time'] ?? null) }}</strong></div>
                <div class="ret-kv"><span>Last update</span><strong>{{ $fmtTs($raw['update_time'] ?? null) }}</strong></div>
                @if(!empty($raw['due_date']))<div class="ret-kv"><span>Due date</span><strong>{{ $fmtTs($raw['due_date']) }}</strong></div>@endif
            </div>

            {{-- Phase 2 placeholder --}}
            <div class="card ret-actions-soon">
                <div class="ret-card-title">Actions</div>
                <p class="text-secondary text-sm" style="margin:0;">Accept, dispute, offer, and proof-upload actions are coming in Phase 2 (we have full API access).</p>
            </div>
        </div>
    </div>
</div>
@endsection
