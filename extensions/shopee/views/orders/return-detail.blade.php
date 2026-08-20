@extends('layouts.channel')
@section('title', 'Return ' . $return->return_sn)
@section('breadcrumb', $return->return_sn)

{{-- Round F Task 1. Rebuilt on the shared `od-` record anatomy
     (components/order-detail.css), the same one every other detail page in
     this application uses, so a return reads like a record here and not like a
     page from another product.

     Five things changed beyond the drawing:

       1. The six hand-rolled `ret-badge-*` colour classes are gone. Status
          tone and label come from App\Support\ChannelStatusTone's
          'shopee.returns' surface, which the Returns list this page is
          reached from already uses, so a status is the same colour and the
          same words on both screens and no marketplace constant reaches the
          operator raw.
       2. Money went through a hardcoded `$cur . number_format(...)`, which
          composed a symbol in the view. It goes through App\Support\Money
          now: the return's own currency when it carries one, the default
          from the currencies table when it does not.
       3. Every "no value" was an em dash. They are words now, and which word
          depends on what is missing, because "Not set" and "Shopee has not
          said" are different facts.
       4. The recommendation banner led with an emoji. Emoji render
          differently on every platform and cannot be aligned or coloured;
          this uses the same od-note the rest of the record pages use.
       5. `$setting` was passed by the controller and never read, and
          `use Illuminate\Support\Str` was imported and never used. Neither is
          referenced here; the controller is untouched.

     Nothing on this page submits. There is no form, no CSRF and no script,
     before or after. --}}
@section('content')
@php
    $raw = is_array($raw ?? null) ? $raw : [];

    $statusStr = (string) ($return->status ?? '');
    $status = strtoupper(trim($statusStr));
    $tone = \App\Support\ChannelStatusTone::toneFor('shopee.returns', $statusStr);
    $statusLabel = \App\Support\ChannelStatusTone::labelFor('shopee.returns', $statusStr);

    // Currency is resolved through App\Support\Money, which reads the symbol
    // off the currencies table, so no symbol is written into this view. A
    // return carrying a code nobody has configured locally falls back to
    // "CODE 1,234.00" rather than a wrong glyph.
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

    // Shopee sends its own constants for the reason, the reverse-logistics
    // stage, the compensation state and every negotiation term:
    // ITEM_WRONG, PENDING_REQUEST, RETURN_REFUND. Those are wire values, not
    // words, and a page that prints them is asking the operator to learn
    // Shopee's vocabulary to read their own return. There is no label map for
    // these the way ChannelStatusTone carries one for statuses (they run to
    // dozens per marketplace and change without notice), so this does the one
    // transform that is always safe and always an improvement: underscores
    // become spaces and the shouting stops. ITEM_WRONG reads as "Item wrong".
    $plain = function ($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        // Left alone if it is already prose: only a value that is entirely
        // upper case and underscores is a marketplace constant.
        if (!preg_match('/^[A-Z0-9_]+$/', $value)) return $value;
        return ucfirst(strtolower(str_replace('_', ' ', $value)));
    };

    // What to do next, in the words a warehouse supervisor would use. Same
    // branching as before; only the wording and the tone class changed. The
    // tone here is the note's own severity, not a status colour: "warn" is
    // the one case where goods are physically coming back and someone has to
    // be ready for them.
    [$adviceText, $adviceTone] = match (true) {
        in_array($status, ['REQUESTED', 'PROCESSING', 'JUDGING', 'SELLER_DISPUTE'], true)
            => ['This return is still with Shopee. There is nothing to do yet, beyond watching it.', ''],
        $status === 'ACCEPTED' && $needsLogistics === true
            => ['The goods are on their way back. Check them against the list below when they arrive, put them back into stock, then set the order to Returned.', 'od-note--warn'],
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

    {{-- What Shopee will actually accept on this return.
         Asked on request rather than on load: it is a live call, and a
         returns list of thirty would otherwise make thirty of them before
         anyone had decided to act on one.

         It reports only what Shopee permits. Nothing here submits - the
         resolutions themselves are still made in Seller Centre until the
         write calls have been exercised against a live shop, because our
         sandbox refuses the permission and a resolution that fails does so
         in front of a customer. --}}
    <div class="rs" data-return-solutions>
        <div class="rs__head">
            <div>
                <h2 class="rs__title">What can be offered</h2>
                <p class="rs__sub">Shopee decides which resolutions are open on a return, and up to how much.</p>
            </div>
            <x-ui.button size="sm" type="button" data-solutions-ask>Ask Shopee</x-ui.button>
        </div>
        <div class="rs__out" data-solutions-out role="status" aria-live="polite"></div>
    </div>

    <div class="od-body">
        {{-- ============ MAIN COLUMN ============ --}}
        <div class="od-col od-col--main">

            {{-- Items. The same `od-` item grid the order detail pages use, so
                 quantity and money line up down the page. Two figures per
                 line rather than one, because a return's question is always
                 "how many of how many, and for how much". --}}
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

                                // "Variation" is the user-facing word across
                                // this application and across every
                                // marketplace it talks to.
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

            {{-- Why. The buyer's reason, their own words, and whatever they
                 photographed. --}}
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

            {{-- The argument, when there is one. --}}
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

        {{-- ============ SIDE COLUMN ============ --}}
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

            {{-- Said plainly, not dressed as a disabled card of buttons.
                 Accepting, disputing and uploading proof are not built yet,
                 and a row of greyed controls would suggest they nearly are. --}}
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
