{{--
    THE LISTING STRIP: the page's spine, on every channel.

    the owner picked this shape on 2026-09-08 from the surface drafts (draft C,
    "the console"), with one amendment: Last push folds into it, so the
    strip answers every standing question about the live item in one line -
    what state is it in, what does it cost there, how much is left, is a
    promotion running, when did we last send anything, and where did those
    settings come from.

    It replaced a card. A card competed with the seven cards beneath it and
    scrolled away with them, which is the wrong behaviour for the one fact
    an operator checks twice.

    THE SHAPE IS THE TEMPLATE. A channel supplies its own figures and its
    own verbs; it never supplies its own arrangement. Anything only one
    channel has (Shopee's couriers, Lazada's variation overrides) lives in
    that channel's own section further down the page, not here.

      channel   'Shopee' | 'Lazada' | 'TikTok Shop' | the store's name
      state     ['label' => string, 'tone' => 'success'|'warning'|'danger'|'neutral']
      figures   list<['k' => string, 'v' => string]>  the channel's own facts, in its own words
      lastPush  ?['when' => string, 'from' => ?string]  null when nothing has ever gone up
      linked    bool  the product is on the channel at all
      health    bool  draw the state, the figures and the last push at all
      verbs     slot  the forms that act on the live item
      notes     slot  refusals, gaps and staleness, drawn under the strip

    HEALTH CAN BE TURNED OFF, and on Shopee it is (the owner, 2026-09-09):
    "What I want to see right now are just fields to edit. Nothing about
    health... We focus on the product edit." Everything the strip knows
    about the live item goes quiet and the bar keeps only the verbs, which
    are how you act rather than what you are told. The accent goes with it:
    a bar in the channel's colour reads as a status banner even when it is
    saying nothing. Written into extensions/CHANNEL-CONTRACT.md as a
    deliberate, temporary divergence rather than a gap to close.
--}}
@props([
    'channel',
    'state' => ['label' => null, 'tone' => 'neutral'],
    'figures' => [],
    'lastPush' => null,
    'linked' => false,
    'health' => true,
])
{{-- The bar and the notes are SIBLINGS, not a wrapper and its children: a
     sticky element travels inside its own parent, so a bar nested in a
     wrapper only stays put until the wrapper scrolls away. --}}
<section class="cl-strip{{ $health ? '' : ' cl-strip--quiet' }}"
         aria-label="{{ $health ? 'On ' . $channel . ' right now' : 'What you can do on ' . $channel }}">
    @if($health)
    <div class="cl-strip__state cl-strip__state--{{ $state['tone'] ?? 'neutral' }}">
        <span class="cl-strip__dot" aria-hidden="true"></span>
        <span class="cl-strip__label">{{ $state['label'] ?? ($linked ? 'Unknown' : 'Not on ' . $channel) }}</span>
    </div>

    <dl class="cl-strip__figures">
        @foreach($figures as $figure)
            <div class="cl-fig">
                <dt>{{ $figure['k'] }}</dt>
                <dd>{{ $figure['v'] }}</dd>
            </div>
        @endforeach

        {{-- Last push is a figure like any other now, not a card in the
             rail where it sat below the fold at every scroll position. --}}
        <div class="cl-fig cl-fig--push">
            <dt>Last push</dt>
            <dd>
                @if($lastPush)
                    {{ $lastPush['when'] }}@if(!empty($lastPush['from']))<span class="cl-fig__from">from {{ $lastPush['from'] }}</span>@endif
                @else
                    Never
                @endif
            </dd>
        </div>
    </dl>
    @endif

    @if(trim($verbs ?? '') !== '')
        <div class="cl-strip__verbs">{{ $verbs }}</div>
    @endif
</section>

@if(trim($notes ?? '') !== '')
    <div class="cl-notes">{{ $notes }}</div>
@endif
