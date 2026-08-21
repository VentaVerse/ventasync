{{-- The channel's own navigation, replacing the global sidebar while inside
     a workspace. Every entry has already passed both permission gates and the
     linkability check in ChannelWorkspace::allows(), so route() cannot throw
     here and no second gate is needed. --}}
<nav class="x-cnav" aria-label="{{ $channelCard->name }} navigation">
    <div class="x-cnav__top">
        <a class="x-cnav__back" href="{{ route('integrations.index') }}">
            <x-ui.icon name="chevron-left" size="12" />
            All channels
        </a>

        {{-- Only reachable while the sidebar is a drawer; above 1024px it is a
             permanent column with nothing to close. The escape key does the
             same job, but a control a pointer can reach has to exist too:
             tapping the scrim is a convention, not an affordance. --}}
        <button type="button" class="x-cnav__close" @click="nav = false"
                aria-label="Close channel navigation">
            <x-ui.icon name="x" size="15" />
        </button>
    </div>

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
        {{-- The wording comes from ChannelWorkspace::badge(), which the
             status board also uses. Two copies of this map is how the two
             screens came to call the same channel different things.

             The label names the actual condition rather than delivering a
             verdict: "Needs attention" covered both an expiring sign-in and
             an overdue sync, and this sidebar shows no figures beside it, so
             there was nothing here to work out which. --}}
        @php($channelBadge = \App\Support\ChannelWorkspace::badge($channelState, $channelStateReason ?? null))
        <span class="x-cnav__state x-cnav__state--{{ $channelState }}">
            {{ $channelBadge['label'] }}
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
    {{-- WHAT IS NOT SOLVED HERE, so the next person does not rediscover it.

         A nav item lights when routeIs() matches its own route exactly. That
         covers every list page. It does NOT cover a record page: a return's
         detail route is ext.{ch}.orders.return_detail, a SIBLING of the nav
         item's ext.{ch}.orders.returns rather than a child, so no wildcard
         reaches it and the reader sits on a page with nothing marked.

         Three URL-based fixes were tried and none could be verified: this
         partial renders in a context where routeIs() resolves but
         request()->path(), request()->is() and Route::current()->uri() all
         come back empty, so a path match silently matches nothing. The real
         fix is for each nav item to declare the route family it owns, which
         means touching every extension's nav manifest rather than this file. --}}

    @if($channelHasOverview)
        <a class="x-cnav__item {{ request()->routeIs('integrations.workspace') ? 'is-active' : '' }}"
           @if(request()->routeIs('integrations.workspace')) aria-current="page" @endif
           href="{{ \App\Support\ChannelWorkspace::overviewUrl($channelKey ?? $channelCard->id) }}">
            <x-ui.icon name="monitor" size="14" /> Overview
        </a>
    @endif

    @foreach($channelGroups as $group)
        <div class="x-cnav__group">
            <span class="x-cnav__group-label">{{ $group['label'] }}</span>
            @foreach($group['items'] as $item)
                {{-- routeIs alone. The URL half of this test read a
                     $cnavBest that nothing in the application has ever
                     defined - wreckage of one of the three attempts the note
                     above describes - so it warned on every render of every
                     workspace page and its branch could not be reached.
                     Removing it changes no highlight; it only stops the
                     partial claiming to do something it does not. --}}
                @php($cnavOn = request()->routeIs($item['route']))
                <a class="x-cnav__child {{ $cnavOn ? 'is-active' : '' }}"
                   @if($cnavOn) aria-current="page" @endif
                   href="{{ $item['url'] }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endforeach
</nav>
