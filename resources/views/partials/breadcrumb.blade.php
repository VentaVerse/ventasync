@php
    // Find the current route in the nav tree so the trail matches the sidebar.
    $trail = [];
    foreach (\App\Support\Navigation::definition() as $entry) {
        if (isset($entry['route']) && \App\Support\Navigation::isActive($entry)) {
            $trail = [$entry['label']];
            break;
        }
        foreach ($entry['items'] ?? [] as $item) {
            if (\App\Support\Navigation::isActive($item)) {
                $trail = [$entry['label'], $item['label']];
                break 2;
            }
        }
    }
@endphp

<span class="x-crumb">
    @foreach($trail as $i => $part)
        @if($i > 0)<span class="x-crumb__sep">/</span>@endif
        <span class="x-crumb__part">{{ $part }}</span>
    @endforeach
    @hasSection('breadcrumb')
        @if($trail)<span class="x-crumb__sep">/</span>@endif
        <span class="x-crumb__part x-crumb__part--current">@yield('breadcrumb')</span>
    @endif
</span>
