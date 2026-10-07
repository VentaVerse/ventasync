<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\Settings\UserGroupController;
use App\Http\Controllers\Settings\SettingController;
use App\Http\Controllers\Settings\SettingsHubController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\ExtensionController;
use App\Http\Controllers\Settings\ErrorLogController;
use App\Http\Controllers\Settings\ApiClientController;
use App\Http\Controllers\Settings\OrderStatusController;

Route::prefix('settings')->group(function () {
    Route::group([], function () {
        Route::get('/', [SettingsHubController::class, 'index'])->name('settings.hub');

        Route::get('/general', [SettingController::class, 'edit'])->name('settings.general')->defaults('tab', 'general');
        Route::get('/website', [SettingController::class, 'edit'])->name('settings.website')->defaults('tab', 'website');
        Route::get('/mail', [SettingController::class, 'edit'])->name('settings.mail')->defaults('tab', 'mail');
        Route::get('/maintenance', [SettingController::class, 'edit'])->name('settings.maintenance')->defaults('tab', 'maintenance');

        Route::get('/edit', [SettingController::class, 'redirectLegacyEdit'])->name('settings.edit');
        Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        Route::get('/extensions', [ExtensionController::class, 'index'])->name('extensions.index');
    });

    Route::group([], function () {
        Route::post('/', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/mail/test', [SettingController::class, 'sendTestMail'])->name('settings.mail.test');

        Route::get('/currencies/create', [CurrencyController::class, 'create'])->name('currencies.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::get('/currencies/{id}/edit', [CurrencyController::class, 'edit'])->name('currencies.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/currencies/{id}', [CurrencyController::class, 'update'])->name('currencies.update');
        Route::delete('/currencies/{id}', [CurrencyController::class, 'destroy'])->name('currencies.destroy');
        Route::post('/currencies/update-rates', [CurrencyController::class, 'updateRates'])->name('currencies.update_rates');

        Route::post('/extensions/install', [ExtensionController::class, 'install'])->name('extensions.install');
        Route::post('/extensions/refresh', [ExtensionController::class, 'refresh'])->name('extensions.refresh');
        Route::post('/extensions/{extension}/toggle', [ExtensionController::class, 'toggle'])->name('extensions.toggle');
        Route::post('/extensions/{extension}/reinstall', [ExtensionController::class, 'reinstall'])->name('extensions.reinstall');
        Route::delete('/extensions/{extension}', [ExtensionController::class, 'uninstall'])->name('extensions.uninstall');
    });

    Route::group([], function () {
        Route::get('/error-log', [ErrorLogController::class, 'index'])->name('error_log.index');
    });
    Route::group([], function () {
        Route::post('/error-log/clear', [ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/error-log/test', [ErrorLogController::class, 'test'])->name('error_log.test');
    });

    Route::group([], function () {
        Route::get('/api', [ApiClientController::class, 'index'])->name('api_clients.index');
    });
    Route::group([], function () {
        Route::get('/api/create', [ApiClientController::class, 'create'])->name('api_clients.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/api', [ApiClientController::class, 'store'])->name('api_clients.store');
        Route::get('/api/{apiClient}/edit', [ApiClientController::class, 'edit'])->whereNumber('apiClient')->name('api_clients.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/api/{apiClient}', [ApiClientController::class, 'update'])->whereNumber('apiClient')->name('api_clients.update');
        Route::post('/api/{apiClient}/rotate', [ApiClientController::class, 'rotate'])->whereNumber('apiClient')->name('api_clients.rotate');
        Route::post('/api/{apiClient}/token', [ApiClientController::class, 'token'])->whereNumber('apiClient')->middleware('throttle:20,1')->name('api_clients.token');
        Route::delete('/api/{apiClient}', [ApiClientController::class, 'destroy'])->whereNumber('apiClient')->name('api_clients.destroy');
    });

    Route::group([], function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });
    Route::group([], function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    Route::group([], function () {
        Route::get('/user-groups', [UserGroupController::class, 'index'])->name('user_groups.index');
    });
    Route::group([], function () {
        Route::get('/user-groups/create', [UserGroupController::class, 'create'])->name('user_groups.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/user-groups', [UserGroupController::class, 'store'])->name('user_groups.store');
        Route::get('/user-groups/{id}/edit', [UserGroupController::class, 'edit'])->name('user_groups.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/user-groups/{id}', [UserGroupController::class, 'update'])->name('user_groups.update');
        Route::post('/user-groups/{id}/duplicate', [UserGroupController::class, 'duplicate'])->name('user_groups.duplicate');
        Route::delete('/user-groups/{id}', [UserGroupController::class, 'destroy'])->name('user_groups.destroy');
    });

    Route::get('/fulfilment', [\App\Http\Controllers\Settings\FulfilmentController::class, 'edit'])->name('settings.fulfilment');
    Route::put('/fulfilment', [\App\Http\Controllers\Settings\FulfilmentController::class, 'update'])->name('settings.fulfilment.update');

    Route::group([], function () {
        Route::get('/order-statuses', [OrderStatusController::class, 'index'])->name('order_statuses.index');
    });
    Route::group([], function () {
        Route::get('/order-statuses/create', [OrderStatusController::class, 'create'])->name('order_statuses.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/order-statuses', [OrderStatusController::class, 'store'])->name('order_statuses.store');
        Route::post('/order-statuses/bulk', [OrderStatusController::class, 'bulkAction'])->name('order_statuses.bulk');
        Route::get('/order-statuses/{id}/edit', [OrderStatusController::class, 'edit'])->name('order_statuses.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/order-statuses/{id}', [OrderStatusController::class, 'update'])->name('order_statuses.update');
        Route::delete('/order-statuses/{id}', [OrderStatusController::class, 'destroy'])->name('order_statuses.destroy');
    });
});

Route::group([], function () {
    Route::get('/api/currencies', [CurrencyController::class, 'lookup'])->name('api.currencies');
});
