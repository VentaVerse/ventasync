{{-- The trail, derived from the URL by App\Support\Breadcrumbs: nothing is
     registered here, and adding a page adds no breadcrumb wiring. This partial
     only renders, which is why both the shell and the channel workspace can
     share it - the channel passes $crumbClass for its own mobile rule.

     A crumb with a url is a link; a crumb without one is plain text, either
     because it is a section heading with no page of its own or because it is
     the page the operator is already standing on. The last plain-text crumb is
     therefore the current page, and it carries --current, which is what the
     channel layout's sub-768px rule keeps on screen when it hides the rest. --}}
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
