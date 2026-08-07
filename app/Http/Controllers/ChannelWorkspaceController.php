<?php

namespace App\Http\Controllers;

use App\Support\ChannelWorkspace;
use Illuminate\Http\Request;

class ChannelWorkspaceController extends Controller
{
    public function show(Request $request, string $channel, ChannelWorkspace $workspace)
    {
        $user = $request->user();

        // The URL says /integrations/venta/2; everything below this line, and
        // everything downstream of it, speaks the colon key `venta:2`. This is
        // the only place the two meet on the way in (ChannelWorkspace::
        // overviewUrl is its counterpart on the way out), so the workspace
        // resolver, StoreLink ids and OrderTab ids all keep one format.
        $channel = ChannelWorkspace::keyFromPath($channel);

        $card = $workspace->card($channel, $user);

        // card() returns null for both an unknown channel and one the user
        // cannot see. Both are a 404 on purpose: a 403 would confirm that a
        // channel the user has no access to exists.
        //
        // On a multi-store channel it also returns null for a bare key
        // (`venta`), an unknown store (`venta:999`) and a malformed one
        // (`venta:abc`), so those land on the same 404 rather than on a
        // page built from a store nobody named.
        //
        // hasWorkspace() keeps direct URLs honest with the board: a channel
        // whose menu has not been converted (no grouped items) has no
        // workspace to show, and rendering a half-built one to a bookmarked
        // URL is worse than a 404. The board already refuses to link here
        // for those channels, by the same test.
        if (! $card || ! $workspace->hasWorkspace($card)) {
            abort(404);
        }

        $state = $workspace->state($card, $channel);

        // channelCard, channelGroups and channelState are deliberately absent
        // here: the layouts.channel view composer supplies all three to every
        // page in this layout, including this one, and passing them from here
        // as well only computed the same values a second time for the
        // composer to overwrite.
        return view('integrations.workspace', [
            'channelHealth' => $workspace->health($card, $channel),
            // Spec 3.4: the Overview answers "what is waiting for me", not
            // just "is the connection alive". These read the same closures
            // the Orders summary page uses, so the two cannot disagree.
            'channelWaiting' => $workspace->waiting($card, $user, $channel),
            // Per spec 3.4, the Overview also carries the channel's most
            // needed action: Connect when it needs setup, otherwise the same
            // secondary action the status board already offers for it. Reads
            // the card's own menu, so no grouped sidebar has to be built here
            // just to answer it.
            'channelAction' => $workspace->action($card, $state, $user, $channel),
        ]);
    }
}
