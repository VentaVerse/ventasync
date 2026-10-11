@php
    $bucket = \App\Channels\Listings\ListingTrouble::asked($bucket ?? null);
@endphp
@if($bucket !== null)
    <div class="cs-filter" role="status">
        <span class="cs-filter__mark" aria-hidden="true"></span>
        <span class="cs-filter__k">{{ \App\Channels\Listings\ListingTrouble::showing($bucket, (int) ($count ?? 0)) }}</span>
        <a class="cs-filter__clear" href="{{ $clear }}">Show all</a>
    </div>
@endif
