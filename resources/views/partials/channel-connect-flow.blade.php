{{-- Connecting this store (the owner chose draft C, 2026-09-05): the steps a
     store goes through, in a strip above the keys and the by-hand tools,
     each with its state and, where one exists, its button. The keys and
     the button that uses them were one flow in two cards; this puts the
     flow where it is read first. Shared by Shopee, Lazada and TikTok.

     $steps      list of ['title', 'hint', 'state', 'tone' (done|todo|failed),
                 'button' => null | ['label', 'variant', and one of
                 'href' (a link), 'post' (a form action), 'attrs' (a button
                 carrying data attributes)]]
     $canManage  manage tier: buttons are drawn only for it --}}
@php $canManage = $canManage ?? false; @endphp
<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Connecting this store</h2>
    </div>
    <ol class="cs-flow">
        @foreach($steps as $i => $step)
            @php $button = $step['button'] ?? null; @endphp
            <li class="cs-flow__step" data-state="{{ $step['tone'] ?? 'todo' }}">
                <span class="cs-step__n">{{ $i + 1 }}</span>
                <div class="cs-flow__body">
                    <span class="cs-step__title">{{ $step['title'] }}
                        @if(!empty($step['hint']))
                            {{-- The last step sits at the right edge: its bubble opens leftward. --}}
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
                                {{-- A plain button in the component's clothes: the data
                                     attribute that names its behaviour (data-setup-open)
                                     cannot ride a component tag as a variable. --}}
                                <button type="button" class="x-btn x-btn--{{ $button['variant'] ?? 'secondary' }} x-btn--sm" {{ $button['attr'] ?? '' }}>{{ $button['label'] }}</button>
                            @endif
                        </div>
                    @endif
                    <span class="cs-flow__state">{{ $step['state'] }}</span>
                </div>
            </li>
        @endforeach
    </ol>
</section>
