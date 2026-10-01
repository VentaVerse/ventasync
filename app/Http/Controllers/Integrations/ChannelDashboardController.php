<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Support\ChannelMetrics;
use App\Support\ChannelWorkspace;
use Illuminate\Http\Request;

/**
 * A channel's landing page: connection state, today's figures, what is
 * waiting. One subclass per channel, living in that channel's extension.
 *
 * THE ROUTE BELONGS TO THE EXTENSION, THE LOGIC LIVES HERE.
 *
 * This replaces core's /integrations/{channel} wildcard, which was the one
 * place the application broke its own rule that core never owns an
 * extension's page. The wildcard's controller was core, so the permission
 * catalog - which derives a key from the controller that answers a route -
 * filed the page under core, exempted it, and left every channel dashboard
 * gated by a legacy key (`view_lazada`) that the permission editor could not
 * grant. A group created after the permissions rebuild could hold every
 * Lazada key on the page and still meet a 404 on the Lazada menu entry.
 *
 * Each subclass pins one channel and derives one honest key
 * (`lazada/dashboard`), enforced at the door like every other page. Sharing
 * this class is fine: sharing a VIEW was never the problem, sharing a ROUTE
 * was.
 */
abstract class ChannelDashboardController extends Controller
{
    /** The extension id this dashboard belongs to. Set by each subclass. */
    protected string $channel;

    public function show(Request $request, ChannelWorkspace $workspace, ChannelMetrics $metrics)
    {
        $user = $request->user();

        /* BY NAME, NEVER BY INJECTION. The route carries a defaults() entry
         * (permission_denial), and Laravel fills unclaimed method arguments
         * from route parameters POSITIONALLY - so an injected ?string $store
         * received the string '404' whenever the optional segment was
         * absent, and every single-store dashboard 404d on a key like
         * `shopee:404`. Asking for the parameter by name cannot collide. */
        $store = $request->route('store') ?? $request->attributes->get('channel.store');

        // The URL says /channels/venta/2; everything below this line speaks
        // the colon key `venta:2` - the same format StoreLink and OrderTab
        // ids use. The channel half is pinned by the subclass, so the URL
        // can no longer name a channel the controller does not belong to.
        $channel = $this->channel . ($store !== null && $store !== '' ? ':' . $store : '');

        $card = $workspace->card($channel, $user);

        // card() returns null for an unknown channel and for one the user
        // cannot see - the card permission is checked inside the gated
        // lookup, on top of this route's own door check. Both are a 404 on
        // purpose: a 403 would confirm that a channel the user has no
        // access to exists.
        //
        // On a multi-store channel it also returns null for a bare key
        // (`venta`), an unknown store (`venta:999`) and a store suffix on a
        // single-store channel (`shopee:3`), so those land on the same 404
        // rather than on a page built from a store nobody named.
        //
        // hasWorkspace() keeps direct URLs honest with the board: a channel
        // whose menu has not been converted has no workspace to show, and
        // rendering a half-built one to a bookmarked URL is worse than a
        // 404.
        if (! $card || ! $workspace->hasWorkspace($card)) {
            abort(404);
        }

        $state = $workspace->state($card, $channel);

        // channelCard, channelGroups and channelState are deliberately
        // absent here: the layouts.channel view composer supplies all three
        // to every page in this layout, including this one.
        //
        // How this channel is doing, which is what the page leads with. The
        // colon key IS the marketplace_source these orders were written
        // with, so scoping costs nothing extra: a two-store seller reads
        // one store per page for free.
        $trend = $metrics->trend($channel);

        /* The store's payouts (the owner, 2026-10-02): its panel in the right
         * column, in the marketplace's own words, for a channel that pays out
         * per order and someone who may see its payouts. Never waits on the
         * marketplace (PayoutBalances::kept). */
        $payoutCard = null;
        if (app(\App\Services\Payouts\PayoutRegistry::class)->channel($this->channel) !== null) {
            try {
                $payoutCard = app(\App\Services\Payouts\PayoutOverview::class)
                    ->cards($user, [$this->channel => [$store !== null && $store !== '' ? (int) $store : null]])[0] ?? null;
            } catch (\Throwable $e) {
                report($e); // a payout problem never takes the Overview down
            }
        }

        return view('channels.workspace', [
            'channelPayoutCard' => $payoutCard,
            'channelTrend' => $trend,
            'channelBestSellers' => $trend ? $metrics->bestSellers($channel) : [],
            'channelHealth' => $workspace->health($card, $channel),
            // Spec 3.4: the Overview answers "what is waiting for me", not
            // just "is the connection alive". These read the same closures
            // the Orders summary page uses, so the two cannot disagree.
            'channelWaiting' => $workspace->waiting($card, $user, $channel),
            // Per spec 3.4, the Overview also carries the channel's most
            // needed action: Connect when it needs setup, otherwise the
            // same secondary action the status board already offers for it.
            'channelAction' => $workspace->action($card, $state, $user, $channel),
        ]);
    }
}
