@props(['title' => 'Nothing here yet', 'description' => null])

{{-- An optional icon slot for a first-run state that wants a moment of
     visual interest rather than text on a blank card. Backward
     compatible: callers that pass none render exactly as before. --}}
<div {{ $attributes->merge(['class' => 'x-empty']) }}>
    @isset($icon)<div class="x-empty__icon" aria-hidden="true">{{ $icon }}</div>@endisset
    <p class="x-empty__title">{{ $title }}</p>
    @if($description)<p class="x-empty__desc">{{ $description }}</p>@endif
    @isset($action)<div class="x-empty__action">{{ $action }}</div>@endisset
</div>
