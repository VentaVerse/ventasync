<?php

use App\Http\Controllers\Fulfilment\OrderPrintController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\CredentialRevealController;

// The Channels section. Required from routes/web.php inside the ['auth']
// group.
//
// RENAMED FROM /integrations (2026-08-23). The menu said Channels and the
// address bar said integrations, which meant the URL taught a different
// vocabulary from the interface. The word survives only in code namespaces
// (App\Integrations\, IntegrationProvider) where it accurately names a
// broader idea - an extension plugging into core surfaces, which purchasing
// does without being a channel.
//
// THE /{channel} WILDCARD IS GONE, DELIBERATELY, AND MUST NOT COME BACK.
// One core route serving every channel's landing page meant the permission
// catalog filed those pages under core, and every channel dashboard
// dropped off the permission editor. Each extension now declares its own
// /channels/{id} dashboard route (name: ext.{id}.dashboard), so the page
// derives that channel's own key. Core offers the menu; the extension owns
// the page.
//
// Fulfilment lives here because fulfilment IS channel work - it reads each
// channel's queue and gates per channel. It has its own top-level nav entry
// only because it is the daily working surface.

Route::prefix('channels')->group(function () {
    // Channels hub (cards view).
    Route::get('/', [IntegrationController::class, 'index'])->name('channels.index');

    // All Listings (Phase D, #184): every product's standing on every
    // channel that can answer, one grid above the per-channel workbenches.
    // All Listings (channels.listings) retired 2026-09-05 (#201): it was the
    // single-store era's "ready to push, per channel" aggregator. With every
    // live channel multi-store, each store's own Listings answers that in
    // context, so the page leaves rather than lingering as a dead door.

    // The fulfilment hub: every channel's open orders, one working surface.
    // Redirects to the first marketplace tab the user may see; otherwise
    // shows the empty state.
    Route::get('/fulfilment', [IntegrationController::class, 'orders'])->name('channels.fulfilment');

    // Pick and packing lists over a selection of orders, for every channel.
    //
    // POST despite being a read: a selection is a list of ids that routinely
    // runs past what belongs in a URL, and the operator arrives by submitting
    // the boxes they ticked. Nothing is written, no marketplace is called,
    // and the controller gates each channel on its own VIEW tier - the same
    // reasoning the Shopee routes file records for tracking and waybill
    // downloads, which are also server-rendered from already-fetched data.
    // The packing check: an order's lines without quantities, and a count
    // to verify. The booking routes refuse an order not counted here.
    Route::get('/fulfilment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfilment\PackingCheckController::class, 'show'])
        ->where('channel', '[a-z]+')->whereNumber('order')
        ->name('fulfilment.packing_check.show');
    Route::post('/fulfilment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfilment\PackingCheckController::class, 'verify'])
        ->where('channel', '[a-z]+')->whereNumber('order')
        ->middleware('throttle:60,1')
        ->name('fulfilment.packing_check.verify');

    Route::post('/fulfilment/print/{channel}/pick', [OrderPrintController::class, 'pick'])
        ->where('channel', '[a-z]+')
        ->name('fulfilment.print.pick');
    Route::post('/fulfilment/print/{channel}/packing', [OrderPrintController::class, 'packing'])
        ->where('channel', '[a-z]+')
        ->name('fulfilment.print.packing');

    Route::get('/module/{module}', [IntegrationController::class, 'module'])
        ->where('module', '[A-Za-z0-9_:-]+')
        ->name('channels.module');

    // Reveal one stored credential, for an operator who holds the channel's
    // manage permission. POST, so the field being asked for never lands in a
    // URL, a browser history entry or an access log.
    Route::post('/{channel}/settings/credential/reveal', CredentialRevealController::class)
        ->where('channel', '[A-Za-z0-9_.-]+')
        ->name('channels.credential.reveal');
});
