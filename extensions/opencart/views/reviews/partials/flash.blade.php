{{--
    Session flash for the review pages.

    layouts.shell renders no flash region of its own, and the legacy
    `#js-flash-container` these pages used to rely on only exists in the
    old layout, so every message the review controller sets would be
    silently dropped once the pages moved. Three keys reach these pages:
    `review_result` (fetch, push all, delete all - an ok flag plus a
    sentence), `status` (push, skip) and `error` (every refusal).
--}}
@php
    $result = session('review_result');
    $ok = session('status');
    $failed = session('error');
@endphp

@if($result || $ok || $failed)
    <div class="rv-flash" role="status" aria-live="polite">
        @if($result)
            <div class="od-note {{ ($result['ok'] ?? false) ? 'od-note--ok' : 'od-note--fail' }}">
                <span class="od-note__body">{{ $result['message'] ?? '' }}</span>
            </div>
        @endif
        @if($ok)
            <div class="od-note od-note--ok"><span class="od-note__body">{{ $ok }}</span></div>
        @endif
        @if($failed)
            <div class="od-note od-note--fail"><span class="od-note__body">{{ $failed }}</span></div>
        @endif
    </div>
@endif
