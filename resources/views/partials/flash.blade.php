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
