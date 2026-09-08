{{--
    The Discount card, shared by every marketplace listing page (#146,
    critique 2026-08-25). One rule shape everywhere: a percent off and a
    window, saved on the channel's own record and pushed as a deliberate,
    confirmed act.

    THE CARD SAYS NOTHING ABOUT ITS STATE (the owner, 2026-09-09). It used to
    carry a Status line - "On Shopee as promotion 508423095844913. Buyers
    see the cut price inside the window." - set at 16px in a 300px rail,
    where it wrapped to four lines and shouted louder than the price it
    described. Every fact in it is already on screen somewhere it belongs:

      is a promotion running   the strip's Promotion figure, live from the channel
      what buyers will pay     the price rule's "after push" figure, struck
      is it saved yet          the button, which reads "Save and push discount"
      which promotion          the End confirm, which names the id it ends

    SAVE AND PUSH IS ONE PRESS. The push acts on the SAVED rule, so a rule
    typed and not yet saved made the button do nothing at all - a dead
    control with no explanation, since an explanation under a control is
    banned here for good reason. The fix is neither an error telling the
    operator to save first nor a button that vanishes as they type: while
    the rule differs from what is saved, the button carries the save with
    it. Clearing the percent shows no push verb at all - there is nothing
    to send, and the page's own Save is the whole act.

    Rendered INSIDE the page's main form (the fields ride Save, and so
    does Save and push discount). The plain push and end forms live
    outside it (nested forms are illegal HTML); see channel-discount-forms,
    which takes the same $discount array.

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
      salePreview    ?float  computed sale price; the channel's own price
                     figure strikes through with it. Not printed here.
      remoteId       ?string the channel's promotion id once pushed
      tracked        bool, whether the channel gives us a promotion id at all
      endClearsRule  bool, whether End also clears the saved rule here
      pushedAt       ?Carbon when an untracked channel last took the push (Lazada)
      liveError      ?string set when the channel did not answer on this load
      saveAndPush    bool, the channel's save handler honours push_discount_after
      ridesPush      bool, the discount goes up with every push of the product and
                     the store applies the window itself; no push or end verb of
                     its own, and the window is optional (WooCommerce)
--}}
@php
    $ridesPush = (bool) ($ridesPush ?? false);
    $pushedAt = $pushedAt ?? null;
    $liveError = $liveError ?? null;
    $saveAndPush = (bool) ($saveAndPush ?? false);
    $hasRule = $savedPct !== null && ($ridesPush || ($savedFrom && $savedTo));

    /* The typed values and the SAVED values are formatted by the same
     * hand, because the card compares them: 15 against 15.00 would read
     * as an edit the moment the page loaded. */
    $pctText = fn ($v) => ($v === null || $v === '')
        ? ''
        : ($step === '1' ? (string) (int) $v : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));

    $savedPctText = $pctText($savedPct);
    $savedFromText = $savedFrom?->format('Y-m-d\TH:i') ?? '';
    $savedToText = $savedTo?->format('Y-m-d\TH:i') ?? '';

    $inputPct = old('sale_percent', $savedPctText !== '' ? $savedPctText : null);
    $inputFrom = old('sale_starts_at', $savedFromText !== '' ? $savedFromText : null);
    $inputTo = old('sale_ends_at', $savedToText !== '' ? $savedToText : null);

    // Nothing goes out while the channel is not answering: a push into
    // silence would report its own failure a moment later.
    $canPush = $canManage && $onMarketplace && $hasRule && $liveError === null;
    $canEnd = $canManage && $onMarketplace && ($remoteId || $pushedAt) && $liveError === null;
    $canSaveAndPush = $canManage && $onMarketplace && $saveAndPush && $liveError === null;

    /* ONE WORD FOR THE WINDOW (the owner, 2026-09-09: "For discount visibility
     * like current and upcoming. How are we handling it?"). A window is two
     * datetimes, and whether it covers today is arithmetic nobody should be
     * asked to do in their head - nor should "did that save?" be a guess.
     * A badge in the heading, in the same grammar as every other state in
     * the house: a square and a word. Never a sentence, which is what the
     * old Status line was and why it went. */
    $badge = null;
    if ($hasRule) {
        if (!$onMarketplace) {
            $badge = ['Saved', 'neutral'];
        } elseif ($ridesPush && (!$savedFrom || !$savedTo)) {
            $badge = ['Saved', 'neutral'];
        } elseif (!$ridesPush && !$remoteId && !$pushedAt) {
            // Saved here and the channel has never been told. The one
            // state that wants a second press, so the one that is amber.
            $badge = ['Not pushed', 'warning'];
        } elseif ($savedTo && $savedTo->isPast()) {
            $badge = ['Ended', 'neutral'];
        } elseif ($savedFrom && $savedFrom->isFuture()) {
            $badge = ['Starts ' . $savedFrom->format('M j'), 'info'];
        } else {
            $badge = ['Running', 'success'];
        }
    }
@endphp
{{-- The Alpine scope compares what is typed against what is SAVED - not
     against what was posted back, which is why the snapshot comes from the
     record and not from old(). After a refused save the fields hold the
     typing and the record still holds the old rule, and that is exactly
     when pushing the saved rule would be the wrong act. --}}
<section class="fm-section"
     x-data="{
        ch: @js($channel),
        sap: @js($canSaveAndPush),
        pct: @js((string) ($inputPct ?? '')),
        from: @js((string) ($inputFrom ?? '')),
        to: @js((string) ($inputTo ?? '')),
        saved: { pct: @js($savedPctText), from: @js($savedFromText), to: @js($savedToText) },
        get dirty() { return this.pct !== this.saved.pct || this.from !== this.saved.from || this.to !== this.saved.to; },
        get mode() {
            if (!this.sap || !this.dirty) return 'push';
            return this.pct ? 'both' : 'none';
        },
        when(v) { if (!v) return ''; var d = new Date(v); return isNaN(d) ? v : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }); },
        get confirmWords() {
            var w = 'Save this listing, then push the discount to ' + this.ch + '? ' + this.pct + '% off';
            if (this.from && this.to) { w += ', ' + this.when(this.from) + ' to ' + this.when(this.to); }
            return w + '. Buyers see the cut price once the window opens.';
        }
     }">
    {{-- What the card is stays in a tooltip, not in a paragraph above the
         fields: an explanation under a control is a hint here (the owner), and
         in a 320px rail a four-line paragraph is most of the card. --}}
    <div class="fm-section__head">
        <h2 class="fm-section__title">Discount</h2>
        <x-ui.hint label="About the discount">Percent off the listed price{{ $ridesPush ? ', with an optional window' : ', for a window' }}.{{ $note !== '' ? ' ' . $note : '' }} Leave the percent empty for no discount.</x-ui.hint>
        @if($badge)
            <x-ui.badge :tone="$badge[1]" x-show="!dirty">{{ $badge[0] }}</x-ui.badge>
        @endif
        <x-ui.badge tone="neutral" x-show="dirty" x-cloak>Not saved</x-ui.badge>
    </div>
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
    @if($ridesPush)
        {{-- No verb of its own: the rule rides Save and the next push. --}}
    @elseif($canManage && $onMarketplace)
        <div class="cc-head-actions">
            {{-- One slot, one verb at a time, so nothing jumps as it is
                 typed into. This button is inside the page's own form and
                 carries its own name: the save runs, then the push. --}}
            <x-ui.button type="submit" x-show="mode === 'push'"
                         form="{{ $prefix }}-discount-push" :disabled="!$canPush"
                         data-confirm-tone="primary" data-confirm-verb="Push discount">Push discount to {{ $channel }}</x-ui.button>
            @if($canSaveAndPush)
                <x-ui.button type="submit" x-show="mode === 'both'" x-cloak
                             name="push_discount_after" value="1"
                             x-bind:data-confirm="confirmWords"
                             data-confirm-tone="primary" data-confirm-verb="Save and push">Save and push discount</x-ui.button>
            @endif
            @if($canEnd)
                <x-ui.button type="submit" form="{{ $prefix }}-discount-end" data-confirm-verb="End discount">End discount now</x-ui.button>
            @endif
        </div>
    @endif

    {{-- NO INSTRUCTIONS UNDER A CONTROL (the owner, 2026-09-09). Three
         sentences used to sit here: push waits until the channel answers,
         set a percent then Save, and push the product first. Each described
         a button that is already disabled or already absent, which is the
         state saying it for itself. "Its not really pretty to look at." --}}
</section>
