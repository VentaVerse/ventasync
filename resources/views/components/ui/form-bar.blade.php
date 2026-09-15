@props(['cancel' => null, 'cancelLabel' => 'Cancel'])
{{-- The one place a record page keeps its commit buttons: pinned to the
     foot of the screen, at the lower right. The order is fixed here, not by
     the page: the note, Cancel, the secondary buttons, then the one primary,
     last and rightmost.

     Slots:
       note       an optional short line on the left, hidden on a phone
       (default)  the secondary buttons
       primary    the page's one primary button --}}
<div {{ $attributes->merge(['class' => 'fm-bar']) }}>
    @isset($note)
        <span class="fm-bar__meta">{{ $note }}</span>
    @endisset
    <div class="fm-bar__actions">
        @if($cancel)
            <a class="fm-cancel" href="{{ $cancel }}" data-guard-leave>{{ $cancelLabel }}</a>
        @endif
        {{ $slot }}
        {{ $primary ?? '' }}
    </div>
</div>
