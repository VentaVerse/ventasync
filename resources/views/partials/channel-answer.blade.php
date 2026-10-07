@php
    $headline = \App\Support\MarketplaceWords::headline($channel, (string) $raw);
    $lapsed = $headline !== null && str_contains($headline, 'sign-in has lapsed');
@endphp
@if($headline)
    {{ $headline }}
    @if(($settingsRoute ?? null) && $lapsed)
        <a class="x-btn x-btn--secondary x-btn--sm fm-note__act" href="{{ route($settingsRoute) }}">Reconnect {{ $channel }}</a>
    @endif
    <details class="fm-note__more">
        <summary>What {{ $channel }} said</summary>
        <span>{{ $raw }}</span>
    </details>
@else
    {{ $raw }}
    @if($settingsRoute ?? null)
        <a class="x-btn x-btn--secondary x-btn--sm fm-note__act" href="{{ route($settingsRoute) }}">Open {{ $channel }} settings</a>
    @endif
@endif
