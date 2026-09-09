{{--
    WHAT THIS LISTING STILL NEEDS, AND ONE BUTTON TO WHERE IT IS FIXED.

    the owner, 2026-09-09, on the sentence that used to sit here: "This message
    is a bit lost. Its not a warning. Its not an error." And on the first
    attempt at a fix: "It is clickable. But it doesnt look like a button...
    Create a dedicated button that screams click me."

    So a blocker LOOKS like one, and the way out is a control with weight
    and shape. The sentence names what is missing and stops - it carries no
    links inside the prose, because half of what it names lives on the
    catalog product rather than on this page, and nothing in a sentence
    tells you which words are the door.

    Shopee and TikTok grew this first; it is one partial on all five since
    VENT2-131, so no channel can drift into its own wording for the same
    condition.

    Expected keys:
      gaps       list<array{code,label}>  the readiness gaps, from the channel's own engine
      channel    string  the channel or store's name, for the sentence
      isLive     bool    already on the store (so it is an update that would be refused)
      productId  int     the catalog product, for the button
      anchors    array<string,string>  (optional) gap code => an anchor on THIS page

    An anchor here MUST be an id this page actually renders. A dead one is
    a button that does nothing at all when pressed, which is worse than no
    button (the owner, 2026-09-09, on TikTok's, which pointed at #tt-category
    while the section was tl-category-section). ChannelParityTest checks
    every anchor against the page.
--}}
@php
    $gaps = array_values(array_filter((array) ($gaps ?? [])));
    $gapAction = $gaps === []
        ? null
        : \App\Integrations\Listings\GapLinks::action($gaps, (int) $productId, (array) ($anchors ?? []));
@endphp
@if($gaps !== [])
    <div class="fm-note fm-note--warn" role="status">
        <div class="fm-note__body">{{ \App\Integrations\Listings\GapLinks::sentence($gaps, $channel, (bool) $isLive) }}@if($gapAction)<a class="x-btn x-btn--primary x-btn--sm fm-note__act" href="{{ $gapAction['href'] }}"@if($gapAction['onPage'] ?? false) data-gap-jump @endif>{{ $gapAction['label'] }}</a>@endif</div>
    </div>
@endif
