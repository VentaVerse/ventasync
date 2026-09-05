{{-- The result of the last Settings-page action, drawn in the section the
     action came from. Two shapes (the owner, 2026-09-05):

       full  - the explorer's answer inside the Explorer panel: the payload
               is the point there, so it shows.
       note  - a connection action (authorise, exchange, refresh) on
               Connection: one plain line, succeeded or a sentence saying
               what went wrong. Never a payload - that is on the API log.

     Callers pass $result, the $channel name used in the marketplace answer,
     and $mode. --}}
@php
    $ok = (bool) ($result['ok'] ?? false);
    $mode = $mode ?? 'full';
    $title = (string) ($result['title'] ?? 'Result');
    $answerText = null;
    if (!$ok) {
        $answerRaw = is_array($result['data'] ?? null) ? $result['data'] : ['body' => ['message' => (string) ($result['data'] ?? '')], 'ok' => false];
        $answerText = \App\Support\MarketplaceAnswer::errorText($channel, $answerRaw);
    }
@endphp
@if($mode === 'note')
    <div class="fm-note {{ $ok ? 'fm-note--ok' : 'fm-note--fail' }} cs-result-note" role="status">
        <div class="fm-note__body">
            @if($ok)
                <strong>{{ $title }} succeeded.</strong>
            @else
                <strong>{{ $title }} did not go through.</strong>
                @include('partials.channel-answer', ['channel' => $channel, 'raw' => $answerText, 'settingsRoute' => null])
                <a class="cs-result-note__log" href="{{ request()->fullUrlWithQuery(['tab' => 'logs']) }}">See the API log</a>
            @endif
        </div>
    </div>
@else
    <section class="fm-section cs-result">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Last result</h2>
            <div class="fm-section__aside">
                <x-ui.badge :tone="$ok ? 'success' : 'danger'">{{ $ok ? 'Succeeded' : 'Failed' }}</x-ui.badge>
            </div>
        </div>
        <p class="fm-section__note">{{ $title }}</p>
        {{-- The REASON is the visible text; the payload is one click away, never
             the message (the owner, 2026-09-03). A success keeps its confirmation
             data in view - that is what was asked for. --}}
        @if(!$ok)
            <p class="fm-section__note fm-section__note--strong">@include('partials.channel-answer', ['channel' => $channel, 'raw' => $answerText, 'settingsRoute' => null])</p>
            <details class="fm-note__more">
                <summary>Full response</summary>
                <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @else
            <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    </section>
@endif
