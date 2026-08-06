{{-- Global navigation. Groups collapse, and the group containing the current
     route opens on load regardless of stored state so the sidebar always
     shows where you are. That override does not overwrite the preference. --}}
<nav class="x-nav" data-nav>
    @foreach($navGroups as $entry)
        @if(isset($entry['route']))
            <a class="x-nav__item {{ request()->routeIs($entry['route']) ? 'is-active' : '' }}"
               href="{{ route($entry['route']) }}">
                <x-ui.icon :name="$entry['icon']" /> {{ $entry['label'] }}
            </a>
        @else
            @php
                $groupActive = collect($entry['items'])->contains(fn ($i) => request()->routeIs($i['route']));
                $key = \Illuminate\Support\Str::slug($entry['label']);
            @endphp
            <div class="x-nav__group" data-nav-group="{{ $key }}" @if($groupActive) data-nav-open @endif>
                <button type="button" class="x-nav__group-toggle" aria-expanded="{{ $groupActive ? 'true' : 'false' }}">
                    <x-ui.icon :name="$entry['icon']" />
                    <span class="x-nav__group-label">{{ $entry['label'] }}</span>
                    <span class="x-nav__group-chevron"><x-ui.icon name="chevron-right" size="12" /></span>
                </button>
                <div class="x-nav__children">
                    @foreach($entry['items'] as $item)
                        <a class="x-nav__child {{ request()->routeIs($item['route']) ? 'is-active' : '' }}"
                           href="{{ route($item['route']) }}">{{ $item['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</nav>
