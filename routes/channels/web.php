<?php

use App\Http\Controllers\Fulfillment\OrderPrintController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\CredentialRevealController;

// No /{channel} wildcard: it files every channel page under core and hides them from the permission editor.

Route::prefix('channels')->group(function () {
    Route::get('/', [ChannelController::class, 'index'])->name('channels.index');

    Route::get('/fulfillment', [ChannelController::class, 'orders'])->name('channels.fulfillment');

    Route::get('/fulfillment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfillment\PackingCheckController::class, 'show'])
        ->where('channel', '[a-z]+')->whereNumber('order')
        ->name('fulfillment.packing_check.show');
    Route::post('/fulfillment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfillment\PackingCheckController::class, 'verify'])
        ->where('channel', '[a-z]+')->whereNumber('order')
        ->middleware('throttle:60,1')
        ->name('fulfillment.packing_check.verify');

    Route::post('/fulfillment/print/{channel}/pick', [OrderPrintController::class, 'pick'])
        ->where('channel', '[a-z]+')
        ->name('fulfillment.print.pick');
    Route::post('/fulfillment/print/{channel}/packing', [OrderPrintController::class, 'packing'])
        ->where('channel', '[a-z]+')
        ->name('fulfillment.print.packing');

    Route::get('/module/{module}', [ChannelController::class, 'module'])
        ->where('module', '[A-Za-z0-9_:-]+')
        ->name('channels.module');

    Route::post('/{channel}/settings/credential/reveal', CredentialRevealController::class)
        ->where('channel', '[A-Za-z0-9_.-]+')
        ->name('channels.credential.reveal');
});
