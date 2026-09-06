{{-- The Ready column (the owner, 2026-09-06): would the next push of this
     product pass every check VentaSync runs before sending? Ready means
     yes, and the push paths refuse through the SAME readiness computation
     that drew this cell, so a Ready product is never turned away by this
     side. Not ready names what is missing in the hint. What only the
     marketplace knows is not judged here; it arrives as the push's
     failure and lives in the Last sync column.

     One cell for every channel's Listings page and group page. The words
     and tones come from ListingState, so no page can disagree with
     another. Expects $state (App\Integrations\Listings\ListingState or
     null). --}}
@php $rs = $state ?? null; @endphp
@if($rs === null)
    <span class="x-cell-muted">Not checked</span>
@else
    @php [$readyLabel, $readyTone] = $rs->readyBadge(); @endphp
    <x-ui.badge :tone="$readyTone">{{ $readyLabel }}</x-ui.badge>
    @if(! $rs->ready && $rs->missing !== [])
        <x-ui.hint label="What is missing before a push">Missing: {{ $rs->missingSummary() }}</x-ui.hint>
    @endif
@endif
