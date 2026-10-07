@php $canManage = $canManage ?? false; @endphp
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Connecting this store</h2>
    </div>
    <ol class="cs-flow">
        @foreach($steps as $i => $step)
            @php $button = $step['button'] ?? null; @endphp
            <li class="cs-flow__step" data-state="{{ $step['tone'] ?? 'todo' }}"
                @if(!empty($step['key'])) data-flow-step="{{ $step['key'] }}" @endif>
                <span class="cs-step__n">{{ $i + 1 }}</span>
                <div class="cs-flow__body">
                    <span class="cs-step__title">{{ $step['title'] }}
                        @if(!empty($step['hint']))
                            <x-ui.hint :class="$loop->last ? 'bl-hint--end' : ''" label="About this step">{{ $step['hint'] }}</x-ui.hint>
                        @endif
                    </span>
                    @if($canManage && $button)
                        <div>
                            @if(isset($button['href']))
                                <x-ui.button size="sm" :variant="$button['variant'] ?? 'secondary'" :href="$button['href']" :target="($button['newTab'] ?? false) ? '_blank' : null" :rel="($button['newTab'] ?? false) ? 'noopener' : null">{{ $button['label'] }}</x-ui.button>
                            @elseif(isset($button['post']))
                                <form method="POST" action="{{ $button['post'] }}">
                                    @csrf
                                    @foreach(($button['fields'] ?? []) as $name => $value)
                                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                    @endforeach
                                    <x-ui.button type="submit" size="sm" :variant="$button['variant'] ?? 'secondary'">{{ $button['label'] }}</x-ui.button>
                                </form>
                            @else
                                <button type="button" class="x-btn x-btn--{{ $button['variant'] ?? 'secondary' }} x-btn--sm" {{ $button['attr'] ?? '' }}>{{ $button['label'] }}</button>
                            @endif
                        </div>
                    @endif
                    <span class="cs-flow__state" @if(!empty($step['key'])) data-flow-state="{{ $step['key'] }}" @endif>{{ $step['state'] }}</span>
                </div>
            </li>
        @endforeach
    </ol>
</section>
