{{--
    What to print when a marketplace did not answer or refused. Known
    cases (a lapsed sign-in, a missing API permission) get a plain
    headline, with the marketplace's own sentence behind "What {channel}
    said"; unknown cases print the marketplace's words as the headline.
    The way out is a BUTTON, not a link dressed as prose: every failure
    surface on the channel pages depends on it being seen.

      channel        'Shopee' | 'Lazada' | 'TikTok Shop'
      raw            the marketplace's message
      settingsRoute  route name, or null
--}}
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
