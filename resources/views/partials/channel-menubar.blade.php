@php
    $mbSectionOn = function (string $u): bool {
        if (strtok($u, '?') !== url()->current()) {
            return false;
        }
        parse_str((string) (parse_url($u, PHP_URL_QUERY) ?: ''), $q);

        return isset($q['tab']) && $q['tab'] === request()->query('tab');
    };

    $mbChannelId = (string) ($channelCard->id ?? '');
    $mbWide = \App\Extensions\ExtensionImages::isTransparent($mbChannelId, 'logo-wide.png');
    $mbSquare = ! $mbWide && \App\Extensions\ExtensionImages::has($mbChannelId, 'logo.png');

    $mbEntries = [];

    if ($channelHasOverview) {
        $mbEntries[] = [
            'kind' => 'link',
            'label' => 'Overview',
            'url' => \App\Support\ChannelWorkspace::overviewUrl($channelKey ?? $channelCard->id),
            'active' => request()->routeIs('ext.*.dashboard'),
        ];
    }

    foreach ($channelGroups as $mbGroup) {
        $mbItems = $mbGroup['items'];

        if (count($mbItems) === 1 && empty($mbItems[0]['children'])) {
            $mbEntries[] = [
                'kind' => 'link',
                'label' => $mbItems[0]['label'],
                'url' => $mbItems[0]['url'],
                'active' => request()->routeIs($mbItems[0]['route']),
            ];
            continue;
        }

        if (count($mbItems) === 1) {
            $mbEntries[] = [
                'kind' => 'menu',
                'label' => $mbItems[0]['label'],
                'items' => array_map(fn ($c) => [
                    'label' => $c['label'], 'url' => $c['url'],
                    'active' => $mbSectionOn($c['url']), 'children' => [],
                ], $mbItems[0]['children']),
                'active' => request()->routeIs($mbItems[0]['route']),
            ];
            continue;
        }

        $mbEntries[] = [
            'kind' => 'menu',
            'label' => $mbGroup['label'],
            'items' => $mbItems,
            'active' => collect($mbItems)->contains(fn ($i) => request()->routeIs($i['route'])),
        ];
    }
@endphp

<div class="x-chband">
    <nav class="x-chnav" aria-label="{{ $channelCard->name }} navigation"
         x-data="{ open: null, hoverable: window.matchMedia('(hover: hover)').matches }"
         @click.outside="open = null"
         @mouseleave="if (hoverable) open = null"
         @keydown.escape.window="open = null">
        <span class="x-chnav__mark">
            @if($mbWide)
                <img class="x-chnav__logo x-chnav__logo--wide" src="{{ \App\Extensions\ExtensionImages::url($mbChannelId, 'logo-wide.png') }}" alt="{{ $channelCard->name }}">
            @else
                @if($mbSquare)
                    <img class="x-chnav__logo" src="{{ \App\Extensions\ExtensionImages::url($mbChannelId, 'logo.png') }}" alt="">
                @endif
                <span class="x-chnav__markname">{{ $channelCard->name }}</span>
            @endif
        </span>
        @foreach($mbEntries as $mi => $entry)
            @if($entry['kind'] === 'link')
                <a class="x-chnav__link {{ $entry['active'] ? 'is-current' : '' }}"
                   @if($entry['active']) aria-current="page" @endif
                   href="{{ $entry['url'] }}">{{ $entry['label'] }}</a>
            @else
                <div class="x-chnav__group {{ $entry['active'] ? 'is-current' : '' }}" data-chnav-group="{{ $entry['label'] }}">
                    <button type="button" class="x-chnav__parent"
                            @click="open = (open === {{ $mi }} ? null : {{ $mi }})"
                            @mouseenter="if (hoverable) open = {{ $mi }}"
                            :aria-expanded="open === {{ $mi }} ? 'true' : 'false'">
                        {{ $entry['label'] }}
                        <x-ui.icon name="chevron-down" size="9" class="x-chnav__chev" />
                    </button>
                    <div class="x-chnav__menu" x-show="open === {{ $mi }}" x-cloak>
                        @foreach($entry['items'] as $item)
                            @php $mbOn = ($item['active'] ?? false) || (!empty($item['route']) && request()->routeIs($item['route'])); @endphp
                            <a class="x-chnav__child {{ $mbOn ? 'is-active' : '' }}"
                               @if($mbOn) aria-current="page" @endif
                               href="{{ $item['url'] }}">{{ $item['label'] }}@if(!empty($item['badge']))<span class="x-chnav__count" title="{{ $item['badge'] }} to look at">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>@endif</a>
                            @foreach($item['children'] ?? [] as $mbChild)
                                <a class="x-chnav__child x-chnav__child--section {{ $mbSectionOn($mbChild['url']) ? 'is-active' : '' }}"
                                   @if($mbSectionOn($mbChild['url'])) aria-current="page" @endif
                                   href="{{ $mbChild['url'] }}">{{ $mbChild['label'] }}</a>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </nav>
</div>
