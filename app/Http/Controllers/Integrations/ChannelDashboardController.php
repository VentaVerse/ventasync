<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Support\ChannelMetrics;
use App\Support\ChannelWorkspace;
use Illuminate\Http\Request;

abstract class ChannelDashboardController extends Controller
{
    protected string $channel;

    public function show(Request $request, ChannelWorkspace $workspace, ChannelMetrics $metrics)
    {
        $user = $request->user();

        // Read the route parameter by name; an injected argument gets filled positionally with the wrong value.
        $store = $request->route('store') ?? $request->attributes->get('channel.store');

        $channel = $this->channel . ($store !== null && $store !== '' ? ':' . $store : '');

        $card = $workspace->card($channel, $user);

        if (! $card || ! $workspace->hasWorkspace($card)) {
            abort(404);
        }

        $state = $workspace->state($card, $channel);

        $trend = $metrics->trend($channel);

        $payoutCard = null;
        if (app(\App\Services\Payouts\PayoutRegistry::class)->channel($this->channel) !== null) {
            try {
                $payoutCard = app(\App\Services\Payouts\PayoutOverview::class)
                    ->cards($user, [$this->channel => [$store !== null && $store !== '' ? (int) $store : null]])[0] ?? null;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return view('channels.workspace', [
            'channelPayoutCard' => $payoutCard,
            'channelTrend' => $trend,
            'channelBestSellers' => $trend ? $metrics->bestSellers($channel) : [],
            'channelHealth' => $workspace->health($card, $channel),
            'channelWaiting' => $workspace->waiting($card, $user, $channel),
            'channelAction' => $workspace->action($card, $state, $user, $channel),
        ]);
    }
}
