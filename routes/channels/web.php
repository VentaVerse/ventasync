<?php

use App\Http\Controllers\Fulfilment\OrderPrintController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Integrations\CredentialRevealController;

// No /{channel} wildcard: it files every channel page under core and hides them from the permission editor.

Route::prefix('channels')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('channels.index');

    Route::get('/fulfilment', [IntegrationController::class, 'orders'])->name('channels.fulfilment');

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

    Route::post('/{channel}/settings/credential/reveal', CredentialRevealController::class)
        ->where('channel', '[A-Za-z0-9_.-]+')
        ->name('channels.credential.reveal');
});
