<?php

use Extensions\installer\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer wizard routes
|--------------------------------------------------------------------------
|
| Gated by InstallationMiddleware (registered globally by InstallerExtension):
|   - When the marker file storage/agila/installed.lock exists, these
|     routes redirect to / (system already installed).
|   - Until install is complete, all other /* routes redirect to /install.
|
| Loaded only when the extensions/installer/ folder exists. Customers can
| `rm -rf extensions/installer/` after install to remove the wizard
| entirely; the rest of the app keeps working because the middleware
| auto-detects existing installs via DB state.
*/
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'index']);
    Route::get('/welcome', [InstallController::class, 'welcome'])->name('welcome');
    Route::post('/welcome', [InstallController::class, 'welcomeSubmit']);
    Route::get('/requirements', [InstallController::class, 'requirements'])->name('requirements');
    Route::get('/requirements/check', [InstallController::class, 'requirementsCheck'])->name('requirements.check');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database/test', [InstallController::class, 'databaseTest'])->name('database.test');
    Route::post('/database', [InstallController::class, 'databaseSubmit']);
    Route::get('/config', [InstallController::class, 'config'])->name('config');
    Route::post('/config', [InstallController::class, 'configSubmit']);
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'adminSubmit']);
    Route::get('/complete', [InstallController::class, 'complete'])->name('complete');
    Route::post('/complete', [InstallController::class, 'completeSubmit']);
});
