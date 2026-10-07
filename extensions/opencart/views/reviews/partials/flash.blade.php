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
