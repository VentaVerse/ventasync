{{-- The Import page's pager (the owner, 2026-09-06: "No cap. But I want a
     pagination instead. 50 products per page.").

     The fetched rows are never saved (the stateless law), so the pages are
     cut in the browser over the rows this render carries: one fetch, fifty
     rows in view at a time, the rest hidden and still ticked if they were
     ticked. channel-import.js fills the count and the numbers and moves the
     pages; this shell wears the x-pager grammar the Products pages use, so
     the two look the same. Hidden until there is more than one page.

     Shared by every channel's import view (extensions/CHANNEL-CONTRACT.md).
     Include with @include('partials.channel-import-pager'). --}}
<nav class="x-pager" data-import-pager data-per-page="50" hidden aria-label="Pages of items">
    <span class="x-pager__count x-num" data-pager-count></span>
    <div class="x-pager__nav">
        <x-ui.button size="sm" data-pager-prev disabled>
            <x-ui.icon name="chevron-left" size="14" /> Previous
        </x-ui.button>
        <ol class="x-pager__pages" aria-label="Pages" data-pager-pages></ol>
        <x-ui.button size="sm" data-pager-next disabled>
            Next <x-ui.icon name="chevron-right" size="14" />
        </x-ui.button>
    </div>
</nav>
