{{--
    Session flash, in the shared note anatomy.

    layouts.shell renders no flash of its own: a page that drops the legacy
    layout also drops the alert block that layout printed, and every
    redirect-with-message from its controller then lands silently. Task 1's
    settings pages each inlined this block; it is one partial now so a page
    cannot forget a key its controller actually sets.

    All four keys in use across the controllers these pages call:
      status / success  a completed action
      info              a no-op the operator should still know about
      error             a refused action (never an exception message)
--}}
@if(session('status') || session('success') || session('info') || session('error'))
<div class="fm-flash" role="status" aria-live="polite">
    @if(session('status'))
        <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('status') }}</div></div>
    @endif
    @if(session('success'))
        <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('success') }}</div></div>
    @endif
    @if(session('info'))
        <div class="fm-note fm-note--warn"><div class="fm-note__body">{{ session('info') }}</div></div>
    @endif
    @if(session('error'))
        <div class="fm-note fm-note--fail"><div class="fm-note__body">{{ session('error') }}</div></div>
    @endif
</div>
@endif
