{{-- Shown above a channel's order list while its Needs attention tab is open: why each status is here, and where to fix it. --}}
@if(($activeTab ?? '') === \App\Support\OrderAttention::TAB && !empty($attention))
    <div class="x-attention-note" role="status">
        <p class="x-attention-note__lead">These orders have no queue until you decide. Nothing about them changes in the meantime.</p>
        <ul class="x-attention-note__list">
            @foreach($attention as $status => $reason)
                <li class="x-attention-note__item x-attention-note__item--{{ $reason === \App\Support\OrderAttention::NOT_MAPPED ? 'bad' : 'attn' }}">
                    <code class="x-attention-note__code">{{ $status }}</code>
                    @if($reason === \App\Support\OrderAttention::NOT_MAPPED)
                        <span>isn't mapped, so stock and revenue for these orders are undecided.</span>
                    @else
                        <span>is mapped, but no tab lists it yet.</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if(!empty($mappingUrl))
            <x-ui.button :href="$mappingUrl">Open status mapping</x-ui.button>
        @endif
    </div>
@endif
