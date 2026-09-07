{{--
    The Discount card, shared by every marketplace listing page (#146,
    critique 2026-08-25). One rule shape everywhere: a percent off and a
    window, saved on the channel's own record and pushed as a deliberate,
    confirmed act. The card names its own state in words, previews the
    sale price where the channel lets us compute it, and only offers the
    verb the state allows: Push is disabled until a rule is saved; End
    appears once the channel holds the discount.

    Rendered INSIDE the page's main form (the fields ride Save). The push
    and end forms live outside it (nested forms are illegal HTML); see
    channel-discount-forms, which takes the same $discount array.

    Expected keys of $discount:
      channel        'Shopee' | 'Lazada' | 'TikTok Shop'
      prefix         id prefix: 'sl' | 'lz' | 'tt'
      canManage      bool
      onMarketplace  bool, the product is live on the channel
      step           input step: '0.01' or '1' (TikTok takes whole percents)
      note           one sentence naming the channel's own constraint, or ''
      savedPct       ?float  the saved rule
      savedFrom      ?Carbon
      savedTo        ?Carbon
      salePreview    ?float  computed sale price, null when the channel cuts the price itself
      remoteId       ?string the channel's promotion id once pushed
      tracked        bool, whether the channel gives us a promotion id at all
      endClearsRule  bool, whether End also clears the saved rule here
      pushedAt       ?Carbon when an untracked channel last took the push (Lazada)
      liveError      ?string set when the channel did not answer on this load
      ridesPush      bool, the discount goes up with every push of the product and
                     the store applies the window itself; no push or end verb of
                     its own, and the window is optional (WooCommerce)
--}}
@php
    $ridesPush = (bool) ($ridesPush ?? false);
    $pushedAt = $pushedAt ?? null;
    $liveError = $liveError ?? null;
    $hasRule = $savedPct !== null && ($ridesPush || ($savedFrom && $savedTo));
    $inputPct = old('sale_percent', $savedPct !== null ? ($step === '1' ? (int) $savedPct : $savedPct) : null);
    $inputFrom = old('sale_starts_at', $savedFrom?->format('Y-m-d\TH:i'));
    $inputTo = old('sale_ends_at', $savedTo?->format('Y-m-d\TH:i'));

    if (!$hasRule) {
        $stateWords = 'No discount set.';
    } elseif ($ridesPush) {
        $stateWords = 'Goes up with every push. ' . $channel . ' applies the window itself.';
    } elseif ($remoteId) {
        $stateWords = 'On ' . $channel . ' as promotion ' . $remoteId . '. Buyers see the cut price inside the window.';
    } elseif ($pushedAt) {
        $stateWords = 'Pushed to ' . $channel . ' ' . $pushedAt->diffForHumans() . '. Buyers see the cut price inside the window.';
    } else {
        $stateWords = 'Saved here, not pushed to ' . $channel . ' yet.';
    }

    // Nothing goes out while the channel is not answering: a push into
    // silence would report its own failure a moment later.
    $canPush = $canManage && $onMarketplace && $hasRule && $liveError === null;
    $canEnd = $canManage && $onMarketplace && ($remoteId || $pushedAt) && $liveError === null;
@endphp
{{-- The Alpine scope answers while the operator types: the Status line
     turns into "Unsaved: 15% off, Aug 26 10:00 to Sep 2 23:59. Save,
     then push." the moment a field differs from what is saved, so the
     round trip to Save is never a mystery. --}}
<div class="fm-card"
     x-data="{
        pct: @js((string) ($inputPct ?? '')),
        from: @js((string) ($inputFrom ?? '')),
        to: @js((string) ($inputTo ?? '')),
        saved: { pct: @js((string) ($inputPct ?? '')), from: @js((string) ($inputFrom ?? '')), to: @js((string) ($inputTo ?? '')) },
        get dirty() { return this.pct !== this.saved.pct || this.from !== this.saved.from || this.to !== this.saved.to; },
        when(v) { if (!v) return ''; var d = new Date(v); return isNaN(d) ? v : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }); },
        ridesPush: @js($ridesPush),
        get words() {
            if (!this.pct) return 'Unsaved: the discount is being cleared. Save to confirm.';
            if (this.ridesPush && !this.from && !this.to) return 'Unsaved: ' + this.pct + '% off, no window. Save; it goes up with the next push.';
            if (!this.from || !this.to) return this.ridesPush ? 'Unsaved: ' + this.pct + '% off; set both the start and the end, or neither, then Save.' : 'Unsaved: ' + this.pct + '% off; set the start and the end, then Save.';
            return 'Unsaved: ' + this.pct + '% off, ' + this.when(this.from) + ' to ' + this.when(this.to) + (this.ridesPush ? '. Save; it goes up with the next push.' : '. Save, then push.');
        }
     }">
    <div class="fm-card__head"><h2 class="fm-card__title">Discount</h2></div>
    <div class="fm-card__body">
        <p class="fm-section__note">Percent off the listed price{{ $ridesPush ? ', with an optional window' : ', for a window' }}.{{ $note !== '' ? ' ' . $note : '' }} Leave the percent empty for no discount.</p>
        <div class="fm-fields fm-fields--3">
            <x-ui.field label="Percent off" for="{{ $prefix }}-sale-percent" name="sale_percent">
                <x-ui.input id="{{ $prefix }}-sale-percent" name="sale_percent" type="number" :step="$step" min="1" max="99"
                            :value="$inputPct" x-model="pct" :disabled="!$canManage" />
            </x-ui.field>
            <x-ui.field label="From" for="{{ $prefix }}-sale-from" name="sale_starts_at">
                <x-ui.input id="{{ $prefix }}-sale-from" name="sale_starts_at" type="datetime-local" :value="$inputFrom" x-model="from" :disabled="!$canManage" />
            </x-ui.field>
            <x-ui.field label="To" for="{{ $prefix }}-sale-to" name="sale_ends_at">
                <x-ui.input id="{{ $prefix }}-sale-to" name="sale_ends_at" type="datetime-local" :value="$inputTo" :min="$inputFrom" x-model="to" :disabled="!$canManage" />
            </x-ui.field>
        </div>
        <div class="fm-stats">
            <div class="fm-stat">
                <span class="fm-stat__k">Status</span>
                <span class="fm-stat__v" x-show="!dirty">{{ $stateWords }}</span>
                <span class="fm-stat__v" x-show="dirty" x-cloak x-text="words"></span>
            </div>
            @if($hasRule && $salePreview !== null)
                <div class="fm-stat">
                    <span class="fm-stat__k">Sale price</span>
                    <span class="fm-stat__v"><x-money :php="$salePreview" /></span>
                </div>
            @endif
        </div>
        @if($ridesPush)
            {{-- No verb of its own: the rule rides Save and the next push. --}}
        @elseif($canManage && $onMarketplace)
            <div class="cc-head-actions">
                <x-ui.button type="submit" form="{{ $prefix }}-discount-push" :disabled="!$canPush"
                             data-confirm-tone="primary" data-confirm-verb="Push discount">Push discount to {{ $channel }}</x-ui.button>
                @if($canEnd)
                    <x-ui.button type="submit" form="{{ $prefix }}-discount-end" data-confirm-verb="End discount">End discount now</x-ui.button>
                @endif
            </div>
            @if($liveError !== null)
                <p class="fm-section__note">Push waits until {{ $channel }} answers; see the live section above.</p>
            @elseif(!$hasRule)
                <p class="fm-section__note">Set a percent, a start and an end, then Save. Push turns on once the rule is saved.</p>
            @endif
        @elseif(!$onMarketplace && !$ridesPush)
            <p class="fm-section__note">The discount joins the live listing; push the product to {{ $channel }} first.</p>
        @endif
    </div>
</div>
