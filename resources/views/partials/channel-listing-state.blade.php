@php $st = $state ?? null; @endphp
@if($st === null)
    <x-ui.badge tone="neutral">Not listed</x-ui.badge>
@elseif($st->state === \App\Integrations\Listings\ListingState::UNKNOWN)
    <x-ui.badge tone="neutral" title="Press Refresh from the store to read its status">{{ $st->label() }}</x-ui.badge>
@elseif($st->state === \App\Integrations\Listings\ListingState::NOT_LISTED)
    <x-ui.badge :tone="$st->tone()">{{ $st->label() }}</x-ui.badge>
    @if($st->reasons)
        <x-ui.hint label="What the store said">{{ implode(' ', $st->reasons) }}</x-ui.hint>
    @endif
@else
    <span @if($st->checkedAt) title="Checked {{ $st->checkedAt->diffForHumans() }}" @endif>
        <x-ui.badge :tone="$st->tone()">{{ $st->label() }}</x-ui.badge>
    </span>
    @if($st->reasons)
        <x-ui.hint label="What the store says">{{ implode(' ', $st->reasons) }}</x-ui.hint>
    @endif
@endif
@if($st?->driftLabel() && !empty($listingUrl) && ($canCompare ?? true))
    @include('partials.catalog-change-button', ['href' => $listingUrl . (str_contains($listingUrl, '?') ? '&' : '?') . 'compare=1'])
@endif
