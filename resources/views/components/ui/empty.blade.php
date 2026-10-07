@props(['title' => 'Nothing here yet', 'description' => null])

<div {{ $attributes->merge(['class' => 'x-empty']) }}>
    @isset($icon)<div class="x-empty__icon" aria-hidden="true">{{ $icon }}</div>@endisset
    <p class="x-empty__title">{{ $title }}</p>
    @if($description)<p class="x-empty__desc">{{ $description }}</p>@endif
    @isset($action)<div class="x-empty__action">{{ $action }}</div>@endisset
</div>
