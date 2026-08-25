{{--
    What to print when a marketplace did not answer or refused. Known
    cases (a lapsed sign-in, a missing API permission) get a plain
    headline naming the way out, with the marketplace's own sentence
    behind "What {channel} said"; unknown cases print the marketplace's
    words as the headline. A settings link rides along whenever the
    page knows the channel's settings route.

      channel        'Shopee' | 'Lazada' | 'TikTok Shop'
      raw            the marketplace's message
      settingsRoute  route name, or null
--}}
@php
    $headline = \App\Support\MarketplaceWords::headline($channel, (string) $raw);
@endphp
@if($headline)
    {{ $headline }}
    @if($settingsRoute ?? null)<a href="{{ route($settingsRoute) }}">Open {{ $channel }} settings</a>.@endif
    <details class="fm-note__more">
        <summary>What {{ $channel }} said</summary>
        <span>{{ $raw }}</span>
    </details>
@else
    {{ $raw }}
    @if($settingsRoute ?? null)<a href="{{ route($settingsRoute) }}">Open {{ $channel }} settings</a> if the sign-in has lapsed.@endif
@endif
