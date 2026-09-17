{{-- The channel's own menu, as a menubar inside the page (the owner, 2026-09-03,
     replacing the swapped-sidebar workspace nav). Parents are the workspace
     groups the old sidebar showed; each opens downward on click or Enter.
     Sticky, so the menu rides along on every page of the channel.

     Two shaping rules, both from the approved draft:
     - a group with ONE item flattens to a direct link named by the item
       (unless the item declares children, in which case the ITEM becomes
       the parent - that is how Settings carries its page sections);
     - the store's own mark opens the bar: its channel's wide logo when the
       channel ships one, else the square mark with the channel's name. The
       bar is the store's, so the mark says whose pages these are on every
       page of it. A channel with no picture shows the name alone.

     Every entry has already passed both permission gates in
     ChannelWorkspace::groups()/allows(), so nothing here re-asks. --}}
@php
    /* A section child (a ?tab= deep link into its item's own page) is
       current when the operator stands on that page with that tab - the
       path must match too, because orders pages use ?tab for their status
       filters. */
    $mbSectionOn = function (string $u): bool {
        if (strtok($u, '?') !== url()->current()) {
            return false;
        }
        parse_str((string) (parse_url($u, PHP_URL_QUERY) ?: ''), $q);

        return isset($q['tab']) && $q['tab'] === request()->query('tab');
    };

    /* The mark: the channel's wide logo when it is drawn on nothing, else
       its square mark beside the channel's name. A lockup with its own
       background would be a block of colour on the bar. */
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
    {{-- hoverable: matchMedia once at init, so a phone's synthetic
         mouseenter (fired on tap, before click) never fights the tap. --}}
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
                            {{-- a pointer that can hover opens on hover; the
                                 nav's own mouseleave closes, and sliding
                                 across the bar switches menus --}}
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
                               href="{{ $item['url'] }}">{{ $item['label'] }}</a>
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
