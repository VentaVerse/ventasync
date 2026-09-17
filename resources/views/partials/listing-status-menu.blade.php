{{-- The menu down the left of a store's Listings page
     (App\Integrations\Listings\StatusMenu builds $menu): the store's product
     groups, and the two switches for what is waiting on the seller. Where a
     product stands with the marketplace is the segment above the table, so
     the rail keeps one question and the table's bar keeps the other. Every
     entry is a link, so the choice lives in the address and survives a
     reload or a share. --}}
<nav class="lsm" aria-label="Product group" data-lsm>
    {{-- The whole store, above the heading: it is what you see when no group
         is chosen, so it is not one of them. --}}
    @if(!empty($menu['everything']))
        <div class="lsm-group">
            <a href="{{ $menu['everything']['url'] }}" class="lsm-item{{ $menu['everything']['active'] ? ' is-active' : '' }}"
               @if($menu['everything']['active']) aria-current="true" @endif>
                <span class="lsm-item__label">{{ $menu['everything']['label'] }}</span>
                <span class="lsm-n">{{ number_format($menu['everything']['count']) }}</span>
            </a>
        </div>
    @endif

    <div class="lsm-group" role="group" aria-labelledby="lsm-grp">
        <div class="lsm-group__hd">
            <span class="lsm-group__k" id="lsm-grp">Product group</span>
            @if(!empty($menu['newGroup']))
                {{-- Making a group acts on the list itself, so it rides the
                     heading rather than sitting among the groups below. --}}
                <a href="{{ $menu['newGroup'] }}" class="lsm-group__add" aria-label="New product group" title="New product group">
                    <x-ui.icon name="plus" size="14" />
                </a>
            @endif
        </div>
        @foreach($menu['groups'] as $item)
            <a href="{{ $item['url'] }}" class="lsm-item{{ $item['active'] ? ' is-active' : '' }}"
               @if($item['active']) aria-current="true" @endif>
                <span class="lsm-item__label">{{ $item['label'] }}</span>
                <span class="lsm-n">{{ number_format($item['count']) }}</span>
            </a>
        @endforeach
    </div>

    {{-- The same shape as a group above: a name, a number, and the rows it
         opens. These two narrow whatever group is chosen rather than
         replacing it, so they read as its companions, not its rivals. --}}
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
