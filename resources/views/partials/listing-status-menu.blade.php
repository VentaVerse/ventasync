<nav class="lsm" aria-label="Filters" data-lsm>
    @if(!empty($menu['everything']))
        <div class="lsm-group">
            <a href="{{ $menu['everything']['url'] }}" class="lsm-item{{ $menu['everything']['active'] ? ' is-active' : '' }}"
               @if($menu['everything']['active']) aria-current="true" @endif>
                <span class="lsm-item__label">{{ $menu['everything']['label'] }}</span>
                <span class="lsm-n">{{ number_format($menu['everything']['count']) }}</span>
            </a>
        </div>
    @endif

    <div class="lsm-group" role="group" aria-labelledby="lsm-filters">
        <span class="lsm-group__k" id="lsm-filters">Filters</span>
        @foreach($menu['flags'] as $flag)
            <a href="{{ $flag['url'] }}" class="lsm-item lsm-flag{{ $flag['on'] ? ' is-on' : '' }}"
               @if($flag['on']) aria-current="true" @endif>
                <span class="lsm-item__label">{{ $flag['label'] }}<span class="x-sr">, {{ $flag['on'] ? 'on' : 'off' }}</span></span>
                <span class="lsm-n lsm-n--{{ $flag['tone'] }}">{{ number_format($flag['count']) }}</span>
            </a>
        @endforeach
    </div>
</nav>
