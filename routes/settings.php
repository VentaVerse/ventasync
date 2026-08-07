<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SettingsHubController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\OrderStatusController;

// IA migration (2026-08-08, Task 4): Settings section. Required from
// routes/web.php inside the ['auth'] group. Every route in this file
// already shared the /settings segment (Order Statuses joined it in Task 3,
// spec decision 2 (the Sales-looking name is historical); the URI and now
// the physical file agree it is Settings), so the whole file is wrapped in
// Route::prefix('settings') with the redundant literal dropped from each
// line. /api/currencies is the one exception: its URI never started with
// /settings, so it keeps its original full literal outside the group.

Route::prefix('settings')->group(function () {
    // ── Website Settings + Currencies + Extensions + Error Log ─────────
    // Settings + Currencies — READ
    Route::middleware(['perm:view_website_settings|manage_website_settings'])->group(function () {
        Route::get('/', [SettingsHubController::class, 'index'])->name('settings.hub');

        // IA migration (2026-08-08, Task 3), spec decision 4: the once-tabbed
        // settings page splits into one real GET route per tab, all sharing
        // this controller method and view. The tab is resolved from the
        // route via ->defaults('tab', …), read with $request->route('tab');
        // the ?tab= query string is no longer consulted server-side.
        Route::get('/general', [SettingController::class, 'edit'])->name('settings.general')->defaults('tab', 'general');
        Route::get('/website', [SettingController::class, 'edit'])->name('settings.website')->defaults('tab', 'website');
        Route::get('/mail', [SettingController::class, 'edit'])->name('settings.mail')->defaults('tab', 'mail');
        Route::get('/maintenance', [SettingController::class, 'edit'])->name('settings.maintenance')->defaults('tab', 'maintenance');

        // The pre-split URI. Its name stays resolvable — layouts/app.blade.php
        // (protected) still calls route('settings.edit') for the sidebar link
        // and the legacy breadcrumb table — but the URI itself now just 301s
        // to whichever tab a stale ?tab= link asked for, or the first tab for
        // a bare visit. See SettingController::redirectLegacyEdit().
        Route::get('/edit', [SettingController::class, 'redirectLegacyEdit'])->name('settings.edit');
        Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        // Extensions browse — list of installed extensions (read).
        Route::get('/extensions', [ExtensionController::class, 'index'])->name('extensions.index');
    });

    // Settings + Currencies + Extensions — WRITE
    Route::middleware(['perm:manage_website_settings'])->group(function () {
        Route::post('/', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/mail/test', [SettingController::class, 'sendTestMail'])->name('settings.mail.test');

        // Currencies — writes (rate updates, CRUD)
        Route::get('/currencies/create', [CurrencyController::class, 'create'])->name('currencies.create');
        Route::post('/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::get('/currencies/{id}/edit', [CurrencyController::class, 'edit'])->name('currencies.edit');
        Route::put('/currencies/{id}', [CurrencyController::class, 'update'])->name('currencies.update');
        Route::delete('/currencies/{id}', [CurrencyController::class, 'destroy'])->name('currencies.destroy');
        Route::post('/currencies/update-rates', [CurrencyController::class, 'updateRates'])->name('currencies.update_rates');

        // Extensions — install / toggle / reinstall / uninstall are writes.
        Route::post('/extensions/install', [ExtensionController::class, 'install'])->name('extensions.install');
        // {extension} (not {id}) because the param is the extension's
        // string slug like "pedallion", not a numeric DB id. The global
        // Route::pattern('id', '[0-9]+') in RouteServiceProvider would
        // otherwise reject every slug and 404 the toggle/reinstall.
        Route::post('/extensions/{extension}/toggle', [ExtensionController::class, 'toggle'])->name('extensions.toggle');
        Route::post('/extensions/{extension}/reinstall', [ExtensionController::class, 'reinstall'])->name('extensions.reinstall');
        Route::delete('/extensions/{extension}', [ExtensionController::class, 'uninstall'])->name('extensions.uninstall');
    });

    // ── Error Log — READ ──
    // Error Log is its own resource (separate `view_error_log` / `manage_error_log`
    // tiers). Viewing the log is read; clearing/test-firing are writes.
    Route::middleware(['perm:view_error_log|manage_error_log'])->group(function () {
        Route::get('/error-log', [ErrorLogController::class, 'index'])->name('error_log.index');
    });
    Route::middleware(['perm:manage_error_log'])->group(function () {
        Route::post('/error-log/clear', [ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/error-log/test', [ErrorLogController::class, 'test'])->name('error_log.test');
    });

    // ── API Applications (Settings → API) — READ ──
    Route::middleware(['perm:view_api_clients|manage_api_clients'])->group(function () {
        Route::get('/api', [ApiClientController::class, 'index'])->name('api_clients.index');
    });
    // ── API Applications — WRITE ──
    Route::middleware(['perm:manage_api_clients'])->group(function () {
        Route::get('/api/create', [ApiClientController::class, 'create'])->name('api_clients.create');
        Route::post('/api', [ApiClientController::class, 'store'])->name('api_clients.store');
        Route::get('/api/{apiClient}/edit', [ApiClientController::class, 'edit'])->whereNumber('apiClient')->name('api_clients.edit');
        Route::put('/api/{apiClient}', [ApiClientController::class, 'update'])->whereNumber('apiClient')->name('api_clients.update');
        Route::post('/api/{apiClient}/rotate', [ApiClientController::class, 'rotate'])->whereNumber('apiClient')->name('api_clients.rotate');
        Route::delete('/api/{apiClient}', [ApiClientController::class, 'destroy'])->whereNumber('apiClient')->name('api_clients.destroy');
    });

    // ── Users — READ ──
    Route::middleware(['perm:view_users|manage_users'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });
    // ── Users — WRITE ──
    Route::middleware(['perm:manage_users'])->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // ── User Groups — READ ──
    Route::middleware(['perm:view_user_groups|manage_user_groups'])->group(function () {
        Route::get('/user-groups', [UserGroupController::class, 'index'])->name('user_groups.index');
    });
    // ── User Groups — WRITE ──
    Route::middleware(['perm:manage_user_groups'])->group(function () {
        Route::get('/user-groups/create', [UserGroupController::class, 'create'])->name('user_groups.create');
        Route::post('/user-groups', [UserGroupController::class, 'store'])->name('user_groups.store');
        Route::get('/user-groups/{id}/edit', [UserGroupController::class, 'edit'])->name('user_groups.edit');
        Route::put('/user-groups/{id}', [UserGroupController::class, 'update'])->name('user_groups.update');
        Route::delete('/user-groups/{id}', [UserGroupController::class, 'destroy'])->name('user_groups.destroy');
    });

    // ── Order Statuses — READ ──
    // IA migration (2026-08-08, Task 3), spec decision 2: this is Settings,
    // not Sales — URI moved under /settings, name untouched (the owner's nav
    // ruling already classified it there; SettingsHubController's "Order
    // Statuses" card already points at the name, not the URI, and self-heals).
    // Task 4 completes the move by relocating the physical registration here
    // too (Task 3 deliberately left it in its original position in web.php).
    Route::middleware(['perm:view_order_statuses|manage_order_statuses'])->group(function () {
        Route::get('/order-statuses', [OrderStatusController::class, 'index'])->name('order_statuses.index');
    });
    // ── Order Statuses — WRITE ──
    Route::middleware(['perm:manage_order_statuses'])->group(function () {
        Route::get('/order-statuses/create', [OrderStatusController::class, 'create'])->name('order_statuses.create');
        Route::post('/order-statuses', [OrderStatusController::class, 'store'])->name('order_statuses.store');
        Route::post('/order-statuses/bulk', [OrderStatusController::class, 'bulkAction'])->name('order_statuses.bulk');
        Route::get('/order-statuses/{id}/edit', [OrderStatusController::class, 'edit'])->name('order_statuses.edit');
        Route::put('/order-statuses/{id}', [OrderStatusController::class, 'update'])->name('order_statuses.update');
        Route::delete('/order-statuses/{id}', [OrderStatusController::class, 'destroy'])->name('order_statuses.destroy');
    });
});

// /api/currencies never carried the /settings segment: kept as its
// original full literal, outside the prefix group.
Route::middleware(['perm:view_website_settings|manage_website_settings'])->group(function () {
    Route::get('/api/currencies', [CurrencyController::class, 'lookup'])->name('api.currencies');
});
