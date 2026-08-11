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
            ),
        ];
    }
    $blOpenGroup = collect($blNavGroups)->firstWhere('active', true)['key'] ?? null;
@endphp

<nav class="x-nav flex flex-col gap-px py-2.5" data-nav aria-label="Main"
     x-data="{ group: @js($blOpenGroup) }">
    @foreach($navGroups as $i => $entry)
        @if(isset($entry['route']))
            @php $isActive = \App\Support\Navigation::isActive($entry); @endphp
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
                @if(($blBadge = $navBadges[$entry['route']] ?? 0) > 0)
                    <span class="ml-auto min-w-[20px] rounded-full bg-attn-fill px-1.5 text-center
                                 text-[11px] font-semibold tabular-nums text-white">{{ number_format($blBadge) }}</span>
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
                    @foreach($entry['items'] as $item)
                        @php $itemActive = \App\Support\Navigation::isActive($item); @endphp
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
