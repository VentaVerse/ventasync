@props(['title' => 'Nothing here yet', 'description' => null])

<div {{ $attributes->merge(['class' => 'x-empty']) }}>
    <p class="x-empty__title">{{ $title }}</p>
    @if($description)<p class="x-empty__desc">{{ $description }}</p>@endif
    @isset($action)<div class="x-empty__action">{{ $action }}</div>@endisset
</div>
