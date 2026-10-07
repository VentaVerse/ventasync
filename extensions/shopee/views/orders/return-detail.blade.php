@extends('layouts.channel')
@section('title', 'Return ' . $return->return_sn)
@section('breadcrumb', $return->return_sn)

@section('content')
@php
    $raw = is_array($raw ?? null) ? $raw : [];

    $statusStr = (string) ($return->status ?? '');
    $status = strtoupper(trim($statusStr));
    $tone = \App\Support\ChannelStatusTone::toneFor('shopee.returns', $statusStr);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.returns', $statusStr);

    $currencyCode = (string) ($return->currency ?? '');
    $money = fn ($amount) => $currencyCode !== ''
        ? \App\Support\Money::foreign((float) $amount, $currencyCode)
        : \App\Support\Money::base((float) $amount);

    // Shopee sends Unix timestamps on this payload, not dates.
    $stamp = function ($ts) {
        if (!$ts) return null;
        try { return \Carbon\Carbon::createFromTimestamp((int) $ts)->format('Y-m-d H:i'); }
        catch (\Throwable $e) { return null; }
    };

    $items          = is_array($raw['item'] ?? null) ? $raw['item'] : [];
    $buyerPhotos    = is_array($raw['image'] ?? null) ? $raw['image'] : [];
    $buyerVideos    = is_array($raw['buyer_videos'] ?? null) ? $raw['buyer_videos'] : [];
    $user           = is_array($raw['user'] ?? null) ? $raw['user'] : [];
    $negotiation    = is_array($raw['negotiation'] ?? null) ? $raw['negotiation'] : [];
    $disputeReasons = is_array($raw['dispute_reason'] ?? null) ? $raw['dispute_reason'] : [];
    $disputeTexts   = is_array($raw['dispute_text_reason'] ?? null) ? $raw['dispute_text_reason'] : [];

    $needsLogistics = $return->needs_logistics;

    $plain = function ($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (!preg_match('/^[A-Z0-9_]+$/', $value)) return $value;
        return ucfirst(strtolower(str_replace('_', ' ', $value)));
    };

    [$adviceText, $adviceTone] = match (true) {
        in_array($status, ['REQUESTED', 'PROCESSING', 'JUDGING', 'SELLER_DISPUTE'], true)
            => ['This return is still with Shopee. There is nothing to do yet, beyond watching it.', ''],
        $status === 'ACCEPTED' && $needsLogistics === true
            => ['The goods are on their way back. Check them against the list below when they arrive, put them back into stock, then set the order to Returned. That last step happens on the order itself, linked under The order below.', 'od-note--warn'],
        $status === 'ACCEPTED' && $needsLogistics === false
            => ['A refund only. Nothing is coming back, so do not put anything into stock.', ''],
        $status === 'REFUND_PAID'
            => ['The refund has been paid and nothing is coming back. Do not put anything into stock.', ''],
        $status === 'SELLER_COMPENSATION'
            => ['Shopee compensated you for this. Leave the order as sold.', 'od-note--ok'],
        in_array($status, ['CANCELLED', 'CLOSED'], true)
            => ['This return is finished and nothing came of it. Leave the order as sold.', 'od-note--ok'],
        default => [null, ''],
    };
@endphp

<div class="od-page" id="shopee-return-detail-page"
     data-solutions-url="{{ route('ext.shopee.orders.return_solutions', ['returnSn' => $return->return_sn]) }}">

    <a class="od-back" href="{{ route('ext.shopee.orders.returns') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Returns
    </a>

    <header class="od-head">
        <div class="od-head__id">
            <h1 class="x-page-title od-title">{{ $return->return_sn }}</h1>
            @if($statusStr !== '')
                <x-ui.badge :tone="$tone">{{ $statusLabel }}</x-ui.badge>
            @endif
        </div>
    </header>

    <div class="od-stamps">
        <span class="od-stamp">
            <span class="od-stamp__k">Refund</span>
            <span class="od-stamp__v">{{ $money($return->refund_amount) }}</span>
        </span>
        <span class="od-stamp">
            <span class="od-stamp__k">Goods coming back</span>
            <span class="od-stamp__v">{{ $needsLogistics === true ? 'Yes' : ($needsLogistics === false ? 'No, refund only' : 'Shopee has not said') }}</span>
        </span>
        @if($stamp($raw['create_time'] ?? null))
            <span class="od-stamp">
                <span class="od-stamp__k">Requested</span>
                <span class="od-stamp__v">{{ $stamp($raw['create_time']) }}</span>
            </span>
        @endif
        @if($stamp($raw['update_time'] ?? null))
            <span class="od-stamp">
                <span class="od-stamp__k">Last change</span>
                <span class="od-stamp__v">{{ $stamp($raw['update_time']) }}</span>
            </span>
        @endif
        @if($stamp($raw['due_date'] ?? null))
            <span class="od-stamp">
                <span class="od-stamp__k">Shopee decides by</span>
                <span class="od-stamp__v">{{ $stamp($raw['due_date']) }}</span>
            </span>
        @endif
    </div>

    @if($adviceText)
        <div class="od-note {{ $adviceTone }}">
            <div class="od-note__body">{{ $adviceText }}</div>
        </div>
    @endif

    <div class="rs" data-return-solutions>
        <div class="rs__head">
            <div>
                <h2 class="rs__title">What can be offered</h2>
                <p class="rs__sub">Shopee decides which resolutions are open on a return, and up to how much. Asking shows the answer here. Nothing is sent to the buyer and nothing is resolved.</p>
            </div>
            <x-ui.button size="sm" type="button" data-solutions-ask>Ask Shopee</x-ui.button>
        </div>
        <div class="rs__out" data-solutions-out role="status" aria-live="polite"></div>
    </div>

    <div class="od-body">
        <div class="od-col od-col--main">

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">What is being returned</h2>
                    <span class="od-section__count x-num">{{ count($items) }}</span>
                </div>

                @if(empty($items))
                    <p class="od-empty">Shopee sent no line detail with this return.</p>
                @else
                    <div class="od-items">
                        <div class="od-items__head">
                            <span>Item</span>
                            <span>Quantity</span>
                            <span>Refund</span>
                        </div>

                        @foreach($items as $item)
                            @php
                                $image = $item['images'][0] ?? ($item['image_url'] ?? null);
                                $orderedKey = ((string) ($item['item_id'] ?? '')) . '_' . ((string) ($item['model_id'] ?? ''));
                                $ordered = $orderedQty[$orderedKey] ?? null;
                                $returning = (int) ($item['amount'] ?? 0);

                                $metaParts = [];
                                if (!empty($item['item_sku'])) $metaParts[] = 'SKU ' . $item['item_sku'];
                                if (!empty($item['variation_sku'])) $metaParts[] = 'Variation ' . $item['variation_sku'];
                            @endphp
                            <div class="od-item">
                                <div class="od-item__media">
                                    @if($image)
                                        <img src="{{ $image }}" alt="" loading="lazy" decoding="async">
                                    @else
                                        <span class="co-item__none">No image</span>
                                    @endif
                                </div>
                                <div class="od-item__body">
                                    <span class="od-item__name">{{ $item['name'] ?? 'Unnamed item' }}</span>
                                    @if(!empty($metaParts))
                                        <span class="co-item__meta">{{ implode(' / ', $metaParts) }}</span>
                                    @endif
                                </div>
                                <div class="od-item__figs">
                                    <span class="od-fig od-fig--qty">
                                        <span class="od-fig__k">Returning</span>
                                        <span class="x-num">{{ $returning }}@if($ordered !== null) of {{ $ordered }}@endif</span>
                                    </span>
                                    <span class="od-fig od-fig--total">
                                        <span class="od-fig__k">Refund</span>
                                        <span class="x-num">{{ $money($item['refund_amount'] ?? 0) }}</span>
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="od-card__foot">
                    <div class="od-ledger">
                        <div class="od-ledger__row od-ledger__row--total">
                            <span class="od-ledger__k">Total refund</span>
                            <span class="od-ledger__v">{{ $money($return->refund_amount) }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Why the buyer is returning it</h2>
                </div>

                <div class="od-card">
                    <div class="od-card__body">
                        <div class="od-fact">
                            <span class="od-fact__k">Reason given</span>
                            <span class="od-fact__v">{{ $return->reason ? $plain($return->reason) : 'None given' }}</span>
                        </div>

                        @if(!empty($return->reason_text))
                            <p class="rt-quote">{{ $return->reason_text }}</p>
                        @endif

                        @if(count($buyerPhotos) || count($buyerVideos))
                            <div class="rt-media">
                                @foreach($buyerPhotos as $photo)
                                    <a class="rt-media__item" href="{{ $photo }}" target="_blank" rel="noopener">
                                        <img src="{{ $photo }}" alt="Photograph the buyer uploaded" loading="lazy" decoding="async">
                                    </a>
                                @endforeach
                                @foreach($buyerVideos as $video)
                                    <a class="rt-media__item" href="{{ $video['video_url'] ?? '#' }}" target="_blank" rel="noopener">
                                        <img src="{{ $video['thumbnail_url'] ?? '' }}" alt="Video the buyer uploaded" loading="lazy" decoding="async">
                                        <span class="rt-media__play" aria-hidden="true"></span>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="fm-section__note">The buyer uploaded no photographs or video.</p>
                        @endif
                    </div>
                </div>
            </section>

            @if(!empty(array_filter($negotiation)) || count($disputeReasons))
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">The dispute</h2>
                </div>

                <div class="od-card">
                    <div class="od-card__body">
                        <div class="od-fields">
                            @if(!empty($negotiation['latest_solution']))
                                <div class="od-fact">
                                    <span class="od-fact__k">Latest proposal</span>
                                    <span class="od-fact__v">{{ $plain($negotiation['latest_solution']) }}</span>
                                </div>
                            @endif
                            @if(($negotiation['latest_offer_amount'] ?? null) !== null)
                                <div class="od-fact">
                                    <span class="od-fact__k">Latest offer, from {{ $negotiation['latest_offer_creator'] ?? 'someone unnamed' }}</span>
                                    <span class="od-fact__v od-fact__v--mono">{{ $money($negotiation['latest_offer_amount']) }}</span>
                                </div>
                            @endif
                            @if(($negotiation['counter_limit'] ?? null) !== null)
                                <div class="od-fact">
                                    <span class="od-fact__k">Counter offers left</span>
                                    <span class="od-fact__v od-fact__v--mono">{{ $negotiation['counter_limit'] }}</span>
                                </div>
                            @endif
                        </div>

                        @foreach($disputeReasons as $index => $reason)
                            <div class="od-fact">
                                <span class="od-fact__k">Dispute reason</span>
                                <span class="od-fact__v">{{ $plain($reason) }}</span>
                            </div>
                            @if(!empty($disputeTexts[$index]))
                                <p class="rt-quote">{{ $disputeTexts[$index] }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>
            @endif
        </div>

        <div class="od-col">

            <div class="od-card">
                <div class="od-card__head"><h2 class="od-card__title">The order</h2></div>
                <div class="od-card__body">
                    <div class="od-fact">
                        <span class="od-fact__k">Shopee order</span>
                        @if($return->order_sn)
                            <a class="od-fact__va" href="{{ route('ext.shopee.orders.show', ['orderSn' => $return->order_sn]) }}">{{ $return->order_sn }}</a>
                        @else
                            <span class="od-fact__v">Not linked</span>
                        @endif
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Sales order</span>
                        @if($return->order && $return->order->catalog_order_id)
                            <a class="od-fact__va" href="{{ url('/sales/orders/' . $return->order->catalog_order_id) }}">{{ $return->order->catalog_order_id }}</a>
                        @else
                            <span class="od-fact__v">Not linked</span>
                        @endif
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Buyer</span>
                        <span class="od-fact__v">{{ $user['username'] ?? 'Not given' }}</span>
                    </div>
                    @if(!empty($user['email']))
                        <div class="od-fact">
                            <span class="od-fact__k">Email</span>
                            <span class="od-fact__v">{{ $user['email'] }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="od-card">
                <div class="od-card__head"><h2 class="od-card__title">The return shipment</h2></div>
                <div class="od-card__body">
                    <div class="od-fact">
                        <span class="od-fact__k">Stage</span>
                        <span class="od-fact__v">{{ $return->reverse_logistics_status ? $plain($return->reverse_logistics_status) : 'Nothing shipping' }}</span>
                    </div>
                    <div class="od-fact">
                        <span class="od-fact__k">Tracking number</span>
                        <span class="od-fact__v od-fact__v--mono">{{ $return->tracking_number ?: ($raw['tracking_number'] ?? '') ?: 'None yet' }}</span>
                    </div>
                    @if(!empty($raw['seller_compensation']['seller_compensation_status']))
                        <div class="od-fact">
                            <span class="od-fact__k">Compensation</span>
                            <span class="od-fact__v">{{ $plain($raw['seller_compensation']['seller_compensation_status']) }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="od-card">
                <div class="od-card__head"><h2 class="od-card__title">Answering this return</h2></div>
                <div class="od-card__body">
                    <p class="fm-section__note">Accepting, disputing, offering an amount and uploading proof are done in Shopee's own back office for now. Everything above is read from Shopee and kept up to date here.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
