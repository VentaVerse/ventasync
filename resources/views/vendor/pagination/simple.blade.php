@if ($paginator->hasPages())
    <nav class="pagination-nav" role="navigation" aria-label="Pagination Navigation">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <ul class="pagination">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <li class="disabled" aria-disabled="true"><span aria-hidden="true">&lsaquo; Prev</span></li>
                @else
                    <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&lsaquo; Prev</a></li>
                @endif

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li class="disabled" aria-disabled="true"><span>{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <li class="active" aria-current="page"><span>{{ $page }}</span></li>
                            @else
                                <li><a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a></li>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <li><a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">Next &rsaquo;</a></li>
                @else
                    <li class="disabled" aria-disabled="true"><span>Next &rsaquo;</span></li>
                @endif
            </ul>

            <span style="font-size:12.5px; color:#94a3b8; white-space:nowrap;">
                Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}
            </span>
        </div>
    </nav>
@endif