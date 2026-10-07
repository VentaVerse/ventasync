@props([
    'channel',
    'state' => ['label' => null, 'tone' => 'neutral'],
    'figures' => [],
    'lastPush' => null,
    'linked' => false,
    'health' => true,
])
<section class="cl-strip{{ $health ? '' : ' cl-strip--quiet' }}"
         aria-label="{{ $health ? 'On ' . $channel . ' right now' : 'What you can do on ' . $channel }}">
    @if($health)
    <div class="cl-strip__state cl-strip__state--{{ $state['tone'] ?? 'neutral' }}">
        <span class="cl-strip__dot" aria-hidden="true"></span>
        <span class="cl-strip__label">{{ $state['label'] ?? ($linked ? 'Unknown' : 'Not on ' . $channel) }}</span>
    </div>

    <dl class="cl-strip__figures">
        @foreach($figures as $figure)
            <div class="cl-fig">
                <dt>{{ $figure['k'] }}</dt>
                <dd>{{ $figure['v'] }}</dd>
            </div>
        @endforeach

        <div class="cl-fig cl-fig--push">
            <dt>Last push</dt>
            <dd>
                @if($lastPush)
                    {{ $lastPush['when'] }}@if(!empty($lastPush['from']))<span class="cl-fig__from">from {{ $lastPush['from'] }}</span>@endif
                @else
                    Never
                @endif
            </dd>
        </div>
    </dl>
    @endif

    @if(trim($verbs ?? '') !== '')
        <div class="cl-strip__verbs">{{ $verbs }}</div>
    @endif
</section>

@if(trim($notes ?? '') !== '')
    <div class="cl-notes">{{ $notes }}</div>
@endif
