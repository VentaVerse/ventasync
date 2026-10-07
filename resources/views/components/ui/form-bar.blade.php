@props(['cancel' => null, 'cancelLabel' => 'Cancel'])
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
