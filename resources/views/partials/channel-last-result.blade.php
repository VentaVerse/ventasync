{{-- The result of the last Settings-page action, drawn in the section the
     action came from: the explorer's payload and answer inside the Explorer
     panel, a token fetch or a save on Connection (the owner, 2026-09-05: "this
     should be contained inside the API explorer"). Callers pass $result and
     the $channel name used in the marketplace answer. --}}
@php $ok = (bool) ($result['ok'] ?? false); @endphp
<section class="fm-section cs-result">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Last result</h2>
        <div class="fm-section__aside">
            <x-ui.badge :tone="$ok ? 'success' : 'danger'">{{ $ok ? 'Succeeded' : 'Failed' }}</x-ui.badge>
        </div>
    </div>
    <p class="fm-section__note">{{ $result['title'] ?? 'Result' }}</p>
    {{-- The REASON is the visible text; the payload is one click away, never
         the message (the owner, 2026-09-03). A success keeps its confirmation
         data in view - that is what was asked for. --}}
    @if(!$ok)
        @php
            $answerRaw = is_array($result['data'] ?? null) ? $result['data'] : ['body' => ['message' => (string) ($result['data'] ?? '')], 'ok' => false];
            $answerText = \App\Support\MarketplaceAnswer::errorText($channel, $answerRaw);
        @endphp
        <p class="fm-section__note fm-section__note--strong">@include('partials.channel-answer', ['channel' => $channel, 'raw' => $answerText, 'settingsRoute' => null])</p>
        <details class="fm-note__more">
            <summary>Full response</summary>
            <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @endif
</section>
