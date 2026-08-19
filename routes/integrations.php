<?php

use App\Http\Controllers\Fulfilment\OrderPrintController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\ChannelWorkspaceController;
use App\Http\Controllers\Integrations\CredentialRevealController;

// IA migration (2026-08-08, Task 4): Integrations section. Required from
// routes/web.php inside the ['auth'] group. Every route here already shared
// the /integrations segment, so the file is wrapped in Route::prefix
// ('integrations') with the redundant literal dropped from each line.
//
// Fulfilment note: the plan lists Fulfilment alongside Integrations for this
// batch, but Fulfilment has no URI space of its own in core: its single nav
// entry (Navigation::definition()'s "Fulfilment" group) just points at
// integrations.orders below. There is nothing to put in a separate
// routes/fulfilment.php; this file is where that nav entry's controller
// method already lives, so it stays here rather than being split for its
// own sake.
//
// Internal order preserved from the original web.php: the wildcard
// workspace route MUST be registered after /integrations/orders and
// /integrations/module/{module}, or it would swallow both.

Route::prefix('integrations')->group(function () {
    // Integrations hub (cards view) and orders entry-point (redirects to
    // first available marketplace tab; otherwise shows empty state)
    Route::get('/', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/orders', [IntegrationController::class, 'orders'])->name('integrations.orders');

    // Pick and packing lists over a selection of orders, for every channel.
    //
    // POST despite being a read: a selection is a list of ids that routinely
    // runs past what belongs in a URL, and the operator arrives by submitting
    // the boxes they ticked. Nothing is written, no marketplace is called,
    // and the controller gates each channel on its own VIEW tier - the same
    // reasoning the Shopee routes file records for tracking and waybill
    // downloads, which are also server-rendered from already-fetched data.
    //
    // Registered before the /{channel} wildcard below, which is the rule this
    // file already keeps for every non-wildcard route.
    Route::post('/orders/print/{channel}/pick', [OrderPrintController::class, 'pick'])
        ->where('channel', '[a-z]+')
        ->name('fulfilment.print.pick');
    Route::post('/orders/print/{channel}/packing', [OrderPrintController::class, 'packing'])
        ->where('channel', '[a-z]+')
        ->name('fulfilment.print.packing');

    Route::get('/module/{module}', [IntegrationController::class, 'module'])
        ->where('module', '[A-Za-z0-9_:-]+')
        ->name('integrations.module');

    // Reveal one stored credential, for an operator who holds the channel's
    // manage permission. POST, so the field being asked for never lands in a
    // URL, a browser history entry or an access log.
    //
    // Registered before the /{channel} wildcard below. That route is a GET so
    // it could not swallow this one, but the ordering is the same rule the
    // rest of this file follows and breaking it here would only teach the
    // next reader that the rule is optional.
    Route::post('/{channel}/settings/credential/reveal', CredentialRevealController::class)
        ->where('channel', '[A-Za-z0-9_.-]+')
        ->name('integrations.credential.reveal');

    // Registered after integrations/orders and integrations/module on purpose:
    // this is a wildcard and would otherwise capture both.
    //
    // IA migration (2026-08-08, Task 2), spec decision 3: a multi-store
    // channel's workspace is addressed in SLASH grammar, /integrations/venta/2,
    // while the key that identifies it everywhere inside the application stays
    // colon-form (`venta:2`, the same string StoreLink and OrderTab ids use).
    // The optional /{digits} tail is what lets one route serve both shapes;
    // ChannelWorkspaceController::show translates the path back to a key.
    //
    // The digits are load-bearing, not decoration. Every channel page moved
    // under /integrations/{channel}/… in Task 2, and this route is
    // registered BEFORE any of them (core routes load ahead of extension
    // providers). Requiring the second segment to be numeric is what stops
    // this wildcard from swallowing /integrations/shopee/orders and turning
    // every seller center page into a workspace Overview.
    Route::get('/{channel}', [ChannelWorkspaceController::class, 'show'])
        ->where('channel', '[A-Za-z0-9_.-]+(?:/[0-9]+)?')
        ->name('integrations.workspace');
});
