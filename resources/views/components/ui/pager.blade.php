@props([
    // A LengthAwarePaginator, or a simple Paginator. A simple paginator knows
    // neither its total nor its last page, so it gets the summary and the two
    // arrows and no numbers - which is the honest rendering rather than a
    // guessed page count.
    'paginator' => null,
    // How many page links to show either side of the current one. Two gives a
    // five-wide window, which is the widest that still fits beside Previous
    // and Next on a phone.
    'side' => 2,
])

@php
    $p = $paginator;
    $lengthAware = $p && method_exists($p, 'lastPage') && method_exists($p, 'total');
    $last = $lengthAware ? (int) $p->lastPage() : null;
    $current = $p ? (int) $p->currentPage() : 1;

    /*
     * The window, as a list of page numbers with nulls for the gaps.
     *
     * First and last are always present, because "jump to the end" is the
     * second most common thing a person wants from a pager and it was
     * previously reachable only by clicking Next eighty-three times. Between
     * them sits the current page with $side neighbours, and a null wherever
     * the run is broken.
     *
     * A gap of exactly one page prints that page instead of an ellipsis: an
     * ellipsis standing in for a single number is wider than the number and
     * tells you less.
     */
    $pages = [];

    if ($lengthAware && $last > 1) {
        $window = range(max(1, $current - $side), min($last, $current + $side));
        $wanted = array_values(array_unique(array_merge([1], $window, [$last])));
        sort($wanted);

        $previous = null;
        foreach ($wanted as $n) {
            if ($previous !== null && $n - $previous > 1) {
                $pages[] = $n - $previous === 2 ? $previous + 1 : null;
            }
            $pages[] = $n;
            $previous = $n;
        }
    }
@endphp

@if($p)
    <div {{ $attributes->merge(['class' => 'x-pager']) }}>
        @if($lengthAware)
            <span class="x-pager__count x-num">{{ number_format($p->firstItem() ?? 0) }} to {{ number_format($p->lastItem() ?? 0) }} of {{ number_format($p->total()) }}</span>
        @endif

        <div class="x-pager__nav">
            <x-ui.button size="sm" :href="$p->previousPageUrl()" :disabled="!$p->previousPageUrl()">
                <x-ui.icon name="chevron-left" size="14" /> Previous
            </x-ui.button>

            @if($pages !== [])
                {{-- The numbers carry their own list semantics so a screen
                     reader announces "page 4 of 84" rather than a bare "4",
                     and the current one is aria-current the same way an active
                     nav item is. --}}
                {{-- Below 560px the strip scrolls rather than pushing the
                     row past the window, and a scroll container opens at
                     zero - so on page 40 of 42 the one number that matters
                     would start off-screen. Centres the current page in its
                     own scrollport, the same way the dashboard's range
                     control does. scrollLeft rather than scrollIntoView,
                     which walks every scrollable ancestor and would move the
                     page too. --}}
                <ol class="x-pager__pages" aria-label="Pages"
                    x-data
                    x-init="$nextTick(() => {
                        const on = $el.querySelector('.is-on');
                        if (! on) return;
                        // Rect deltas, not offsetLeft. offsetLeft is measured
                        // from the offsetParent, and the <li> between the item
                        // and this container makes that the wrong origin - it
                        // reported page 5 of 8 as sitting at the far right end
                        // of the strip and scrolled there.
                        const item = on.getBoundingClientRect();
                        const box = $el.getBoundingClientRect();
                        $el.scrollLeft += (item.left - box.left) - (box.width - item.width) / 2;
                    })">
                    @foreach($pages as $n)
                        <li>
                            @if($n === null)
                                <span class="x-pager__gap" aria-hidden="true">&hellip;</span>
                            @elseif($n === $current)
                                <span class="x-pager__page is-on" aria-current="page">
                                    <span class="x-sr">Page </span>{{ $n }}<span class="x-sr"> of {{ number_format($last) }}, current page</span>
                                </span>
                            @else
                                <a class="x-pager__page" href="{{ $p->url($n) }}">
                                    <span class="x-sr">Page </span>{{ $n }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            <x-ui.button size="sm" :href="$p->nextPageUrl()" :disabled="!$p->nextPageUrl()">
                Next <x-ui.icon name="chevron-right" size="14" />
            </x-ui.button>
        </div>
    </div>
@endif
