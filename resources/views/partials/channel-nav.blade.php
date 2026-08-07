{{-- The channel's own navigation, replacing the global sidebar while inside
     a workspace. Every entry has already passed both permission gates and the
     linkability check in ChannelWorkspace::allows(), so route() cannot throw
     here and no second gate is needed. --}}
<nav class="x-cnav" aria-label="{{ $channelCard->name }} navigation">
    <a class="x-cnav__back" href="{{ route('integrations.index') }}">
        <x-ui.icon name="chevron-left" size="12" />
        All channels
    </a>

    {{-- The modifier, rather than :has(), so the mobile strip can keep the
         store visible without depending on selector support. --}}
    <div class="x-cnav__head {{ ($channelStoreLabel ?? null) ? 'x-cnav__head--store' : '' }}">
        <span class="x-cnav__name">{{ $channelCard->name }}</span>
        {{-- Which store this workspace belongs to. A multi-store channel
             serves one workspace per store off the same set of routes, so
             without this the two are indistinguishable at a glance and an
             operator can push stock to the wrong shop believing they are in
             the other one. Absent on single-store channels, where the
             channel name above already answers it. --}}
        @if($channelStoreLabel ?? null)
            <span class="x-cnav__store">{{ $channelStoreLabel }}</span>
        @endif
        {{-- "Not tracked" is a statement about instrumentation, not a
             verdict on the connection: this channel exposes no token state
             and no last-sync figure, so there is nothing to call healthy or
             unhealthy. Claiming "Needs attention" here contradicted the
             status board, which calls the same channel Connected. --}}
        <span class="x-cnav__state x-cnav__state--{{ $channelState }}">
            {{ ['connected' => 'Connected', 'attention' => 'Needs attention', 'setup' => 'Not connected', 'untracked' => 'Not tracked'][$channelState] ?? 'Not tracked' }}
        </span>
    </div>

    {{-- Overview is the one destination gated on the CARD permission rather
         than a route's own perm: middleware, because /integrations/{channel}
         re-asks the card question in ChannelWorkspaceController::show. An
         operator holding only view_shopee_orders reaches /shopee/orders but
         404s on the workspace entry, so rendering this link for them is a
         dead end at the top of their own sidebar. --}}
    {{-- $channelKey, not the card id: on a multi-store channel the Overview
         belongs to THIS store ("venta:2"), and the bare card id is a 404. --}}
    @if($channelHasOverview)
        <a class="x-cnav__item {{ request()->routeIs('integrations.workspace') ? 'is-active' : '' }}"
           href="{{ \App\Support\ChannelWorkspace::overviewUrl($channelKey ?? $channelCard->id) }}">
            <x-ui.icon name="monitor" size="14" /> Overview
        </a>
    @endif

    @foreach($channelGroups as $group)
        <div class="x-cnav__group">
            <span class="x-cnav__group-label">{{ $group['label'] }}</span>
            @foreach($group['items'] as $item)
                <a class="x-cnav__child {{ request()->routeIs($item['route']) ? 'is-active' : '' }}"
                   href="{{ $item['url'] }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endforeach
</nav>
