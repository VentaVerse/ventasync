{{-- One listing-state cell for any channel: the words and tones come from
     ListingState, so no page can disagree with another. Expects $state
     (App\Integrations\Listings\ListingState or null) and $listingUrl. --}}
@php $st = $state ?? null; @endphp
@if($st === null)
    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
@elseif($st->state === \App\Integrations\Listings\ListingState::UNKNOWN)
    <x-ui.badge tone="neutral" title="Press Refresh from the store to read its status">{{ $st->label() }}</x-ui.badge>
@elseif($st->state === \App\Integrations\Listings\ListingState::NOT_LISTED)
    <x-ui.badge :tone="$st->tone()">{{ $st->label() }}</x-ui.badge>
    {{-- Whether a push would pass is the Ready column's (partials.channel-ready). --}}
    @if($st->reasons)
        <x-ui.hint label="What the store said">{{ implode(' ', $st->reasons) }}</x-ui.hint>
    @endif
@else
    <span @if($st->checkedAt) title="Checked {{ $st->checkedAt->diffForHumans() }}" @endif>
        <x-ui.badge :tone="$st->tone()">{{ $st->label() }}</x-ui.badge>
    </span>
    @if($st->driftLabel())
        <span class="cc-checked"><x-ui.badge tone="warning">{{ $st->driftLabel() }}</x-ui.badge>
            <x-ui.hint label="What changed">{{ implode(' ', $st->reasons) }}</x-ui.hint></span>
    @elseif($st->reasons)
        <x-ui.hint label="What the store says">{{ implode(' ', $st->reasons) }}</x-ui.hint>
    @endif
@endif
