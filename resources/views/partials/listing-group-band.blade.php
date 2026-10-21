@php
    $band = $groupBand ?? ['current' => null, 'groups' => []];
    $currentGroup = collect($band['groups'])->firstWhere('id', $band['current'] ?? null);
@endphp
<section class="fm-section lg-band" aria-labelledby="lg-band-title">
    <div class="fm-section__head">
        <h2 class="fm-section__title" id="lg-band-title">Product group</h2>
    </div>
    <p class="lg-band__line">
        @if($currentGroup)
            <a href="{{ $currentGroup['url'] }}">{{ $currentGroup['name'] }}</a>
        @else
            <span class="x-cell-muted">No group</span>
        @endif
    </p>
</section>
