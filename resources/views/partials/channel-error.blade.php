{{-- The Error cell, one drawing for every channel's Listings page and group
     pages (the owner, 2026-09-07). It answers one question and nothing else: is
     anything wrong with this row right now?

       no error  -> None
       an error  -> what the channel said, in full

     It replaced the Last sync cell, which mixed when, what and a staleness
     note into one column and needed a rule for each. The owner: "If we even put
     last sync and time, that would have to have a criteria and scenarios and
     that would be a lot."

     An error clears itself: the scheduled refresh re-runs the link check, and
     a successful push or sync clears the message it wrote. Nothing here has
     to decide whether an old failure still counts.

     $error  ?string  the message, or null when the row is healthy --}}
@php
    $error = trim((string) ($error ?? ''));
    // 120 shown against a column stored at 480: an untitled truncation left
    // up to 360 characters of what the channel actually said unreachable.
    $clipped = \Illuminate\Support\Str::limit($error, 120);
@endphp
@if($error === '')
    <span class="x-cell-muted">None</span>
@elseif($clipped === $error)
    <span class="cc-err">{{ $error }}</span>
@else
    {{-- <details>, not a tooltip: a title attribute waits a second, never
         appears on a phone, and cannot be reached from the keyboard. --}}
    <details class="cc-reason">
        <summary class="cc-reason__summary">
            <span class="cc-err cc-reason__clip">{{ $clipped }}</span>
            <span class="cc-reason__toggle">
                <x-ui.icon name="chevron-down" size="12" class="cc-reason__chevron" />
                <span class="cc-reason__label">Full reason</span>
            </span>
        </summary>
        <p class="cc-err cc-reason__full">{{ $error }}</p>
    </details>
@endif
