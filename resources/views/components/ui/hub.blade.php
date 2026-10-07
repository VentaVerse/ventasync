@props(['groups' => [], 'placeholder' => 'Search'])

@php
    $hay = fn (array $d) => strtolower($d['label'] . ' ' . ($d['description'] ?? '') . ' ' . implode(' ', $d['keywords'] ?? []));
    $allHays = [];
    foreach ($groups as $items) { foreach ($items as $d) { $allHays[] = $hay($d); } }
@endphp

<div class="x-hub"
     x-data="{
        q: '',
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
        any(list) { return list.some(h => this.hit(h)); }
     }">

    <div class="x-hub__search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
        </svg>
        <input type="search" class="x-hub__input" x-model="q" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}">
    </div>

    @foreach($groups as $groupName => $items)
        @php $groupHays = array_map($hay, $items); @endphp
        <section class="x-hub__group" x-show="any(@js($groupHays))">
            <h2 class="x-hub__group-title">{{ $groupName }}</h2>
            <div class="x-hub__grid">
                @foreach($items as $d)
                    @continue(!\Route::has($d['route']))
                    <a href="{{ route($d['route']) }}" class="x-hub__card" x-show="hit(@js($hay($d)))">
                        <span class="x-hub__card-icon">
                            <x-ui.icon :name="$d['icon'] ?? 'square'" />
                        </span>
                        <span class="x-hub__card-body">
                            <span class="x-hub__card-title">{{ $d['label'] }}</span>
                            <span class="x-hub__card-desc">{{ $d['description'] ?? '' }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="x-hub__empty" x-cloak x-show="q.trim() !== '' && !any(@js($allHays))">
        Nothing matches "<span x-text="q"></span>".
    </p>
</div>
