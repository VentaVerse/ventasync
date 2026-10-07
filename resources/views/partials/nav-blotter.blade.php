@php
    $blNavGroups = [];
    foreach ($navGroups as $i => $entry) {
        if (isset($entry['route'])) {
            continue;
        }
        $key = 'g' . $i;
        $blNavGroups[$i] = [
            'key' => $key,
            'active' => collect($entry['items'])->contains(
                fn ($item) => \App\Support\Navigation::isActive($item)
                    || collect($item['children'] ?? [])->contains(fn ($c) => \App\Support\Navigation::isActive($c))
            ),
        ];
    }
    $blOpenGroup = collect($blNavGroups)->firstWhere('active', true)['key'] ?? null;
    $blOpenSub = null;
    foreach ($navGroups as $i => $entry) {
        foreach ($entry['items'] ?? [] as $j => $item) {
            if (collect($item['children'] ?? [])->contains(fn ($c) => \App\Support\Navigation::isActive($c))) {
                $blOpenSub = 'g' . $i . 'i' . $j;
            }
        }
    }
@endphp

<nav class="x-nav flex flex-col gap-px py-2.5" data-nav aria-label="Main"
     x-data="{ group: @js($blOpenGroup), sub: @js($blOpenSub) }">
    @foreach($navGroups as $i => $entry)
        @if(isset($entry['route']))
            @php $isActive = \App\Support\Navigation::isActive($entry); @endphp
            @if($entry['route'] === 'channels.index' && \App\Support\Navigation::canAddStore())
                <div class="x-nav__row flex items-stretch">
                    <a class="x-nav__item flex flex-1 items-center gap-2.5 px-4 py-1.5 text-[13.5px] font-medium no-underline
                              {{ $isActive
                                  ? 'bg-side-active text-white font-semibold'
                                  : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                       href="{{ route($entry['route'], $entry['params'] ?? []) }}"
                       @if($isActive) aria-current="page" @endif
                    >
                        <x-ui.icon :name="$entry['icon']" size="15" />
                        <span>{{ $entry['label'] }}</span>
                    </a>
                    <a class="x-nav__add {{ $isActive ? 'bg-side-active text-white' : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                       href="{{ route('channels.index', ['add' => 1]) }}" aria-label="Add store" title="Add store">
                        <x-ui.icon name="plus" size="13" />
                    </a>
                </div>
                @continue
            @endif
            <a class="x-nav__item flex items-center gap-2.5 px-4 py-1.5 text-[13.5px] font-medium no-underline
                      {{ $isActive
                          ? 'bg-side-active text-white font-semibold'
                          : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
               href="{{ route($entry['route'], $entry['params'] ?? []) }}"
               @if($isActive) aria-current="page" @endif
            >
                <x-ui.icon :name="$entry['icon']" size="15" />
                <span>{{ $entry['label'] }}</span>
                @if(($blBadge = $navBadges[$entry['route']] ?? 0) > 0)
                    <span class="ml-auto x-nav__badge">{{ number_format($blBadge) }}</span>
                @endif
            </a>
        @else
            @php $key = $blNavGroups[$i]['key']; @endphp
            <div class="x-nav__group">
                <button type="button"
                        @click="group = (group === '{{ $key }}' ? null : '{{ $key }}')"
                        :aria-expanded="group === '{{ $key }}' ? 'true' : 'false'"
                        class="flex w-full items-center gap-2.5 px-4 py-1.5 text-left text-[13.5px]
                               font-medium text-side-ink hover:bg-side-hover hover:text-white">
                    <x-ui.icon :name="$entry['icon']" size="15" />
                    <span class="flex-1">{{ $entry['label'] }}</span>
                    <span class="transition-transform duration-150 {{ $blOpenGroup === $key ? 'rotate-90' : '' }}"
                          :class="{ 'rotate-90': group === '{{ $key }}' }">
                        <x-ui.icon name="chevron-right" size="12" />
                    </span>
                </button>
                {{-- Keep the object-form :class binding; Alpine cannot remove a class the server rendered otherwise. --}}
                <div class="bl-collapse {{ $blOpenGroup === $key ? 'is-open' : '' }}"
                     :class="{ 'is-open': group === '{{ $key }}' }"
                     :inert="group !== '{{ $key }}'">
                    <div class="bl-collapse__inner flex flex-col gap-px">
                    @foreach($entry['items'] as $j => $item)
                        @if(!empty($item['children']))
                            @php
                                $subKey = $key . 'i' . $j;
                                $subActive = collect($item['children'])->contains(fn ($c) => \App\Support\Navigation::isActive($c));
                            @endphp
                            <div class="x-nav__sub">
                                <button type="button"
                                        @click="sub = (sub === '{{ $subKey }}' ? null : '{{ $subKey }}')"
                                        :aria-expanded="sub === '{{ $subKey }}' ? 'true' : 'false'"
                                        class="flex w-full items-center gap-2 py-1.5 pr-4 pl-11 text-left text-[13px]
                                               {{ $subActive ? 'text-white font-semibold' : 'text-side-ink' }} hover:bg-side-hover hover:text-white">
                                    <span class="flex-1">{{ $item['label'] }}</span>
                                    <span class="transition-transform duration-150 {{ $blOpenSub === $subKey ? 'rotate-90' : '' }}"
                                          :class="{ 'rotate-90': sub === '{{ $subKey }}' }">
                                        <x-ui.icon name="chevron-right" size="11" />
                                    </span>
                                </button>
                                <div class="bl-collapse {{ $blOpenSub === $subKey ? 'is-open' : '' }}"
                                     :class="{ 'is-open': sub === '{{ $subKey }}' }"
                                     :inert="sub !== '{{ $subKey }}'">
                                    <div class="bl-collapse__inner flex flex-col gap-px">
                                    @foreach($item['children'] as $child)
                                        @php $childActive = \App\Support\Navigation::isActive($child); @endphp
                                        <a class="x-nav__child x-nav__child--3 py-1.5 pr-4 pl-[60px] text-[12.5px] no-underline
                                                  {{ $childActive
                                                      ? 'bg-side-active text-white font-semibold'
                                                      : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                                           href="{{ route($child['route'], $child['params'] ?? []) }}"
                                           @if($childActive) aria-current="page" @endif
                                        >{{ $child['label'] }}</a>
                                    @endforeach
                                    </div>
                                </div>
                            </div>
                            @continue
                        @endif
                        @php $itemActive = \App\Support\Navigation::isActive($item); @endphp
                        @if($item['route'] === 'channels.index' && \App\Support\Navigation::canAddStore())
                            <div class="x-nav__row flex items-stretch">
                                <a class="x-nav__child flex-1 py-1.5 pl-11 pr-2 text-[13px] no-underline
                                          {{ $itemActive
                                              ? 'bg-side-active text-white font-semibold'
                                              : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                                   href="{{ route($item['route'], $item['params'] ?? []) }}"
                                   @if($itemActive) aria-current="page" @endif
                                >{{ $item['label'] }}</a>
                                <a class="x-nav__add {{ $itemActive ? 'bg-side-active text-white' : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                                   href="{{ route('channels.index', ['add' => 1]) }}" aria-label="Add store" title="Add store">
                                    <x-ui.icon name="plus" size="13" />
                                </a>
                            </div>
                            @continue
                        @endif
                        <a class="x-nav__child py-1.5 pr-4 pl-11 text-[13px] no-underline
                                  {{ $itemActive
                                      ? 'bg-side-active text-white font-semibold'
                                      : 'text-side-ink hover:bg-side-hover hover:text-white' }}"
                           href="{{ route($item['route'], $item['params'] ?? []) }}"
                           @if($itemActive) aria-current="page" @endif
                        >{{ $item['label'] }}</a>
                    @endforeach
                    </div>
                </div>
            </div>
        @endif
    @endforeach
</nav>
