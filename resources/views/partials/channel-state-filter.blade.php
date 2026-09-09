{{--
    WHY THIS LIST IS NARROW, in the words that sent you here (#240).

    A dashboard chip says "3 listings need attention" and links straight to
    this page filtered to those three. Arriving at a shorter table with no
    explanation reads as a page that lost rows, so the filter says itself,
    in the same sentence, with the way out beside it.

    Rendered only while a ?state= filter is on; the rest of the time it
    draws nothing at all.

      bucket  'attention' | 'drift' | null
      count   how many rows the filter left
      clear   the URL for this page with the filter taken off
--}}
@php
    $bucket = \App\Integrations\Listings\ListingTrouble::asked($bucket ?? null);
@endphp
@if($bucket !== null)
    <div class="cs-filter" role="status">
        <span class="cs-filter__mark" aria-hidden="true"></span>
        <span class="cs-filter__k">{{ \App\Integrations\Listings\ListingTrouble::showing($bucket, (int) ($count ?? 0)) }}</span>
        <a class="cs-filter__clear" href="{{ $clear }}">Show all</a>
    </div>
@endif
