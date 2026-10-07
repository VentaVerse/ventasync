@php
    $__crumbs = \App\Support\Breadcrumbs::for(request());
    $__last = count($__crumbs) - 1;
@endphp

<span class="x-crumb{{ isset($crumbClass) ? ' '.$crumbClass : '' }}">
    @foreach($__crumbs as $__i => $__crumb)
        @if($__i > 0)<span class="x-crumb__sep">/</span>@endif
        @if($__crumb['url'] !== null)
            <a class="x-crumb__part x-crumb__link" href="{{ $__crumb['url'] }}">{{ $__crumb['label'] }}</a>
        @else
            @php $__class = 'x-crumb__part'.($__i === $__last ? ' x-crumb__part--current' : ''); @endphp
            <span class="{{ $__class }}">{{ $__crumb['label'] }}</span>
        @endif
    @endforeach
</span>
