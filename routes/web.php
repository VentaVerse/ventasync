<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::get('/', [\App\Http\Controllers\HomeController::class, 'index'])->name('root');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/ui-kit-v2', fn () => view('ui-kit-v2'))->name('ui_kit_v2');
});

Route::middleware(['auth'])->group(base_path('routes/channels/web.php'));

// Public because the marketplaces hold no session; each controller verifies its own channel's signature.
Route::post('/webhooks/{channel}', [\App\Http\Controllers\Integrations\WebhookController::class, 'receive'])
    ->where('channel', '[a-z0-9_-]+')
    ->name('channels.webhooks.receive');

Route::middleware(['auth'])->group(function () {

    Route::post('/automations/batch', [\App\Http\Controllers\AutomationsController::class, 'batchUpdate'])
        ->name('automations.batch_update');
    Route::post('/automations/{id}', [\App\Http\Controllers\AutomationsController::class, 'update'])
        ->whereNumber('id')
        ->name('automations.update');
    Route::post('/automations/{id}/run', [\App\Http\Controllers\AutomationsController::class, 'run'])
        ->whereNumber('id')
        ->name('automations.run');
    Route::post('/automations/runs/{run}/step', [\App\Http\Controllers\AutomationsController::class, 'runStep'])->whereNumber('run')->name('automations.run_step');
    Route::post('/automations/runs/{run}/stop', [\App\Http\Controllers\AutomationsController::class, 'runStop'])->whereNumber('run')->name('automations.run_stop');
    Route::get('/automations/runs/{run}', [\App\Http\Controllers\AutomationsController::class, 'runState'])->whereNumber('run')->name('automations.run_state');

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    Route::get('/palette/search', [\App\Http\Controllers\PaletteController::class, 'search'])->name('palette.search');
});

Route::middleware(['auth'])->group(base_path('routes/catalog.php'));
Route::middleware(['auth'])->group(base_path('routes/settings.php'));
Route::middleware(['auth'])->group(base_path('routes/sales.php'));

require __DIR__.'/auth.php';

require __DIR__.'/redirects.php';
