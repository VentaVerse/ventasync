<?php

namespace App\Http\Controllers;

use App\Support\ChannelWorkspace;
use Illuminate\Http\Request;

class ChannelWorkspaceController extends Controller
{
    public function show(Request $request, string $channel, ChannelWorkspace $workspace)
    {
        $user = $request->user();
        $card = $workspace->card($channel, $user);

        // card() returns null for both an unknown channel and one the user
        // cannot see. Both are a 404 on purpose: a 403 would confirm that a
        // channel the user has no access to exists.
        if (! $card) {
            abort(404);
        }

        $groups = $workspace->groups($card, $user);
        $state = $workspace->state($card);

        return view('integrations.workspace', [
            'channelCard' => $card,
            'channelGroups' => $groups,
            'channelState' => $state,
            'channelHealth' => $workspace->health($card),
            // Per spec 3.4, the Overview also carries the channel's most
            // needed action: Connect when it needs setup, otherwise the same
            // secondary action the status board already offers for it. Built
            // from $groups/$state already resolved above rather than a
            // second pass over the registry.
            'channelAction' => $workspace->action($card, $state, $groups),
        ]);
    }
}
