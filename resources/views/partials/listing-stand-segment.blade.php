<div class="lss-row">
    <div class="lss" role="group" aria-label="Where it stands">
        @foreach($menu['stand'] as $place)
            <a href="{{ $place['url'] }}" class="lss__b{{ $place['on'] ? ' is-on' : '' }}"
               @if($place['on']) aria-current="true" @endif>
                <span class="lss__label">{{ $place['label'] }}</span>
                <span class="lss__n">{{ number_format($place['count']) }}</span>
            </a>
        @endforeach
    </div>
</div>
