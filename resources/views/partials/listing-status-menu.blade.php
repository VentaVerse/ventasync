{{-- The status menu down the left of a store's Listings page
     (App\Integrations\Listings\StatusMenu builds $menu). Where it stands is
     one tree; Needs you is two switches that narrow it. Every entry is a link,
     so the choice lives in the address and survives a reload or a share. --}}
<nav class="lsm" aria-label="Listing status" data-lsm>
    <div class="lsm-group" role="group" aria-labelledby="lsm-where">
        <span class="lsm-group__k" id="lsm-where">Where it stands</span>
        @foreach($menu['tree'] as $item)
            <a href="{{ $item['url'] }}" class="lsm-item{{ $item['active'] ? ' is-active' : '' }}{{ $item['parent'] ? ' is-parent' : '' }}"
               @if($item['active']) aria-current="true" @endif>
                <span class="lsm-item__label">{{ $item['label'] }}</span>
                <span class="lsm-n{{ $item['tone'] ? ' lsm-n--' . $item['tone'] : '' }}">{{ number_format($item['count']) }}</span>
            </a>
            @if($item['kids'] !== [])
                <div class="lsm-kids">
                    @if($item['meta'])
                        <span class="lsm-kids__meta">{{ $item['meta'] }}</span>
                    @endif
                    @foreach($item['kids'] as $kid)
                        <a href="{{ $kid['url'] }}" class="lsm-item lsm-item--kid{{ $kid['active'] ? ' is-active' : '' }}"
                           @if($kid['active']) aria-current="true" @endif>
                            <span class="lsm-item__label">{{ $kid['label'] }}</span>
                            <span class="lsm-n{{ $kid['tone'] ? ' lsm-n--' . $kid['tone'] : '' }}">{{ number_format($kid['count']) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>

    <div class="lsm-group" role="group" aria-labelledby="lsm-needs">
        <span class="lsm-group__k" id="lsm-needs">Needs you</span>
        @foreach($menu['flags'] as $flag)
            <a href="{{ $flag['url'] }}" class="lsm-item lsm-flag{{ $flag['on'] ? ' is-on' : '' }}">
                <span class="lsm-flag__box" aria-hidden="true"></span>
                <span class="lsm-item__label">{{ $flag['label'] }}<span class="x-sr">, {{ $flag['on'] ? 'on' : 'off' }}</span></span>
                <span class="lsm-n lsm-n--{{ $flag['tone'] }}">{{ number_format($flag['count']) }}</span>
            </a>
        @endforeach
    </div>
</nav>
