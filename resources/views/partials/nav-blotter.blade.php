{{-- Global navigation for the v2.2 shell.

     Reads the same App\Support\Navigation::groups() the legacy sidebar reads,
     so permission gating, route resolution and active detection are unchanged:
     an entry that reaches this file is already one the current user is allowed
     to see. Only the rendering is new.

     One group is open at a time. The open group is held on the <nav> rather
     than on each group, which is what makes opening one close the others: a
     single value cannot name two groups. The group containing the current
     route starts open, so the sidebar shows where you are without waiting for
     JavaScript.

     NOTE: no data-nav-group attribute here, deliberately. resources/js/nav-groups.js
     drives the LEGACY sidebar off that attribute and expects a
     .x-nav__group-toggle inside every element carrying it. This partial has
     no such element, so opting in would make that script throw. --}}
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
    // The third layer (trial, 2026-09-06): which sub-group holds the current page.
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
                {{-- All channels with a plus beside it, the same pair the
                     group branch below draws. A one-item group folds into a
                     flat row (Navigation::flattenSingles), and the row it
                     folds into has to keep everything the child had - this
                     is the affordance that discovered it. --}}
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
                {{-- Work waiting on this entry, when there is any. The map
                     comes from a composer, so this stays a lookup: the partial
                     never learns which entry is special. Zero prints nothing,
                     because an empty badge is a thing to check. --}}
                {{-- One class, because this badge needs a rule per THEME and a
                     utility cannot express that.

                     The reasoning that picked bg-attn over bg-attn-fill was
                     right and only half the problem: it measured the LIGHT
                     value. --bl-attn is #9e5c05 in light, where white reads
                     5.27:1, and #e8a851 in dark, where white reads 2.07:1 -
                     so the count telling staff there is work waiting was the
                     least legible thing in the sidebar every evening. Dark
                     ink on amber in dark theme, which is the same answer
                     DESIGN.md already records for .bl-stat--amber. Styles in
                     components/nav-badge.css. --}}
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
                    {{-- Rendered rotated by the server for the open group, for
                         the same reason the panel below is rendered open: with
                         the rotation applied only after Alpine boots, the
                         chevron of the group you are already in span 90 degrees
                         on every single page load. --}}
                    <span class="transition-transform duration-150 {{ $blOpenGroup === $key ? 'rotate-90' : '' }}"
                          :class="{ 'rotate-90': group === '{{ $key }}' }">
                        <x-ui.icon name="chevron-right" size="12" />
                    </span>
                </button>
                {{-- Collapse animates both ways. The 0fr/1fr grid row is the
                     CSS-only way to transition to a height nobody measured;
                     x-show would snap. The inner element clips during the
                     transition, and inert keeps the closed group's links out
                     of the tab order rather than merely out of sight. --}}
                {{-- is-open is rendered by the SERVER, not left to Alpine.
                     Alpine applies :class after it boots, so the group began
                     every page load closed and then transitioned open - which
                     is what made clicking a child look like the nav collapsed
                     and re-opened itself. Rendered open, there is nothing to
                     animate; Alpine takes over from the same state.

                     THE BINDING MUST BE THE OBJECT FORM, and the server class
                     above is why. Alpine only removes classes IT added, so
                     `group === 'x' && 'is-open'` could never take back a class
                     the server had written: the expression simply evaluated
                     false and added nothing. Opening a second group therefore
                     left the first one - the one matching the current route -
                     visibly open at full height while :inert, which IS removed
                     correctly because it is an attribute binding, quietly
                     disabled every link inside it. A menu that stays on screen
                     and silently ignores clicks. The object form states the
                     class both ways, so false removes it. --}}
                <div class="bl-collapse {{ $blOpenGroup === $key ? 'is-open' : '' }}"
                     :class="{ 'is-open': group === '{{ $key }}' }"
                     :inert="group !== '{{ $key }}'">
                    <div class="bl-collapse__inner flex flex-col gap-px">
                    @foreach($entry['items'] as $j => $item)
                        @if(!empty($item['children']))
                            {{-- The third layer (trial, 2026-09-06): a channel
                                 under Channels, its stores beneath it. --}}
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
                            {{-- All channels with a plus beside it (the owner,
                                 2026-09-06): the plus lands on the board with
                                 the Add store modal already open. --}}
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
