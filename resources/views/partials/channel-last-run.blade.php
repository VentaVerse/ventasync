{{-- Last run (audit, 2026-09-05; one partial for every channel, 2026-09-06):
     when the store last pulled and pushed, from its own timestamps. Drawn
     directly under "Connecting this store" on every channel, in that order
     (the owner, 2026-09-06). A paused store has no run to show.

     $show   whether to draw at all (the store is enabled)
     $stats  label => Carbon|string|null; a timestamp reads "x ago", a
             string reads as it is, null reads Never --}}
@php $show = $show ?? true; $stats = $stats ?? []; @endphp
@if($show && $stats !== [])
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Last run</h2>
    </div>
    <div class="cs-stats">
        @foreach($stats as $label => $at)
            <div class="cs-stat">
                <span class="cs-stat__k">{{ $label }}</span>
                <span class="cs-stat__v">
                    @if($at === null || $at === '')
                        Never
                    @elseif($at instanceof \DateTimeInterface)
                        {{ \Carbon\Carbon::instance($at)->diffForHumans() }}
                    @elseif(is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}/', $at))
                        {{ \Carbon\Carbon::parse($at)->diffForHumans() }}
                    @else
                        {{ $at }}
                    @endif
                </span>
            </div>
        @endforeach
    </div>
</section>
@endif
