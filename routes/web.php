<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

// Root URL, core dispatcher. Delegates to any registered RootRouteHandler
// integration (Shopee claims it when ?code/?shop_id is present for OAuth),
// otherwise falls through to a redirect to /dashboard.
Route::get('/', [\App\Http\Controllers\HomeController::class, 'index'])->name('root');

// IA migration (2026-08-08, Task 4): routes/web.php is now a thin requirer.
// Core resources moved into per-section files (below), each carrying the
// ['auth'] middleware Laravel applies via Route::group()'s file-path form:
// identical to a require() inside a closure, but idiomatic (the same
// mechanism RouteServiceProvider historically used for web.php itself).
// The handful of routes below don't belong to any one nav section
// (dashboard, the UI kit reference page, the universal automations
// scheduler, global search, the command palette) so they stay here rather
// than being forced into a section file for tidiness. Order preserved from
// the pre-split file: Integrations was required first, matching where its
// block sat before this task.
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Reference page for every UI primitive, in both themes. Not a business
    // route: just gated behind the same auth middleware as the rest of admin.
    Route::get('/ui-kit-v2', fn () => view('ui-kit-v2'))->name('ui_kit_v2');
});

Route::middleware(['auth'])->group(base_path('routes/integrations.php'));

Route::middleware(['auth'])->group(function () {
    // Universal admin scheduler, Automations tab on each integration
    // settings page POSTs here. The route is shared across integrations
    // because the underlying table is uniform; permission gating happens
    // on the parent integration's settings page.
    Route::post('/automations/batch', [\App\Http\Controllers\AutomationsController::class, 'batchUpdate'])
        ->name('automations.batch_update');
    Route::post('/automations/{id}', [\App\Http\Controllers\AutomationsController::class, 'update'])
        ->whereNumber('id')
        ->name('automations.update');
    // /dashboard/chart-data and /dashboard/platform-data are gone (Round F,
    // Task 3). They existed only to re-feed a Chart.js canvas after the
    // range toggle changed; the dashboard now renders both trend charts and
    // the channel ranking as server-side SVG, so the range toggle is three
    // ordinary links to /dashboard?range=... and there is nothing to fetch.
    // The mobile app's own feed is untouched: routes/api.php still serves
    // /api/dashboard/chart-data from Api\DashboardController.

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    Route::get('/palette/search', [\App\Http\Controllers\PaletteController::class, 'search'])->name('palette.search');
});

// ─────────────────────────────────────────────────────────────────────────
//  Phase 4.5, view/manage tier split for ALL core resources, unchanged by
//  this task: read routes gated by 'view_X|manage_X', write routes gated by
//  'manage_X'. See each section file's own header for the detail this
//  comment used to carry when everything lived in one file.
// ─────────────────────────────────────────────────────────────────────────
Route::middleware(['auth'])->group(base_path('routes/catalog.php'));
Route::middleware(['auth'])->group(base_path('routes/settings.php'));
Route::middleware(['auth'])->group(base_path('routes/sales.php'));

require __DIR__.'/auth.php';

// IA migration (2026-08-08): temporary 301 shims for URIs that moved under
// their nav section prefix. See routes/redirects.php for details. NOTE:
// this file loads before extension providers boot, so these shims register
// AHEAD of extension routes; that is why every shim names a moved family
// explicitly and none may ever be a catch-all (a catch-all here would
// shadow, for example, Shopee's OAuth callback at /shopee).
require __DIR__.'/redirects.php';
