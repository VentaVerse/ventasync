<?php

use Extensions\opencart\Controllers\OpenCartSettingsController;
use Extensions\opencart\Controllers\OpenCartProductGroupController;
use Extensions\opencart\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store}/settings', [OpenCartSettingsController::class, 'showSettings'])->name('ext.opencart.settings.show');
    Route::get('/opencart/settings', [OpenCartSettingsController::class, 'index'])->name('ext.opencart.index');

    Route::get('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'index'])->name('ext.opencart.product-groups.index');
    Route::get('/opencart/{store}/product-groups/{group}/edit', [OpenCartProductGroupController::class, 'edit'])->name('ext.opencart.product-groups.edit');
    Route::get('/opencart/{store}/product-groups/{group}/products', [OpenCartProductGroupController::class, 'products'])->name('ext.opencart.product-groups.products');
    Route::get('/opencart/{store}/product-groups/search-products', [OpenCartProductGroupController::class, 'searchProducts'])->name('ext.opencart.product-groups.searchProducts');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/opencart/stores', [OpenCartSettingsController::class, 'createStore'])->name('ext.opencart.stores.store');

    Route::post('/opencart/save', [OpenCartSettingsController::class, 'save'])->name('ext.opencart.save');
    Route::delete('/opencart/{id}', [OpenCartSettingsController::class, 'destroy'])->name('ext.opencart.destroy');
    Route::post('/opencart/{store}/delete', [OpenCartSettingsController::class, 'destroyStore'])->whereNumber('store')->name('ext.opencart.stores.destroy');
    Route::post('/opencart/test', [OpenCartSettingsController::class, 'testConnection'])->name('ext.opencart.test');
    Route::post('/opencart/sync', [OpenCartSettingsController::class, 'syncNow'])->name('ext.opencart.sync');
    Route::post('/opencart/sync-date', [OpenCartSettingsController::class, 'saveSyncDate'])->name('ext.opencart.sync_date');
    Route::post('/opencart/push', [OpenCartSettingsController::class, 'pushNow'])->name('ext.opencart.push');
    Route::post('/opencart/push-qty', [OpenCartSettingsController::class, 'pushQtyNow'])->name('ext.opencart.push_qty');
    Route::post('/opencart/verify-password', [OpenCartSettingsController::class, 'verifyPassword'])->name('ext.opencart.verify_password');
    Route::post('/opencart/fetch-oc-statuses', [OpenCartSettingsController::class, 'fetchOcStatuses'])->name('ext.opencart.fetch_oc_statuses');
    Route::post('/opencart/save-status-map', [OpenCartSettingsController::class, 'saveOrderStatusMap'])->name('ext.opencart.save_status_map');
    Route::post('/opencart/save-review-settings', [OpenCartSettingsController::class, 'saveReviewSettings'])->name('ext.opencart.save_review_settings');

    Route::post('/opencart/{store}/products/{product}/push', [OpenCartSettingsController::class, 'pushProduct'])->name('ext.opencart.products.push');

    Route::get('/opencart/{store}/product-groups/create', [OpenCartProductGroupController::class, 'create'])->name('ext.opencart.product-groups.create')
        // A form page is a manage surface even though it is a GET.
        ->defaults('permission_tier', 'manage');
    Route::post('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'store'])->name('ext.opencart.product-groups.store');
    Route::put('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'update'])->name('ext.opencart.product-groups.update');
    Route::delete('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'destroy'])->name('ext.opencart.product-groups.destroy');
    Route::post('/opencart/{store}/product-groups/{group}/push', [OpenCartProductGroupController::class, 'push'])->name('ext.opencart.product-groups.push');
    Route::post('/opencart/{store}/product-groups/{group}/add-products', [OpenCartProductGroupController::class, 'addProducts'])->name('ext.opencart.product-groups.addProducts');
    Route::delete('/opencart/{store}/product-groups/{group}/products/{product}', [OpenCartProductGroupController::class, 'removeProduct'])->name('ext.opencart.product-groups.removeProduct');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store}/orders', [\Extensions\opencart\Controllers\OpenCartOrderController::class, 'index'])->name('ext.opencart.orders.index');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/catalog/reviews', [ReviewController::class, 'index'])->name('ext.opencart.reviews.index');
    Route::get('/catalog/reviews/{id}', [ReviewController::class, 'show'])->name('ext.opencart.reviews.show')->whereNumber('id');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/catalog/reviews/{id}/push', [ReviewController::class, 'pushToOpenCart'])->name('ext.opencart.reviews.push')->whereNumber('id');
    Route::post('/catalog/reviews/{id}/skip', [ReviewController::class, 'skip'])->name('ext.opencart.reviews.skip')->whereNumber('id');
    Route::post('/catalog/reviews/bulk-push', [ReviewController::class, 'bulkPush'])->name('ext.opencart.reviews.bulk_push');
    Route::post('/catalog/reviews/fetch', [ReviewController::class, 'fetch'])->name('ext.opencart.reviews.fetch');
    Route::post('/catalog/reviews/push-all', [ReviewController::class, 'pushAll'])->name('ext.opencart.reviews.push_all');
    Route::post('/catalog/reviews/delete-all', [ReviewController::class, 'deleteAll'])->name('ext.opencart.reviews.delete_all');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store}/products/import', [\Extensions\opencart\Controllers\OpenCartImportController::class, 'importPage'])->whereNumber('store')->name('ext.opencart.products.import');
    Route::get('/opencart/{store}/products/search-catalog', [\Extensions\opencart\Controllers\OpenCartImportController::class, 'searchCatalog'])->whereNumber('store')->name('ext.opencart.products.search_catalog');
    Route::post('/opencart/{store}/products/import-one', [\Extensions\opencart\Controllers\OpenCartImportController::class, 'importOne'])->whereNumber('store')->name('ext.opencart.products.import_one');
    Route::post('/opencart/{store}/products/import-selected', [\Extensions\opencart\Controllers\OpenCartImportController::class, 'importSelected'])->whereNumber('store')->name('ext.opencart.products.import_selected');
    Route::post('/opencart/{store}/products/link-item', [\Extensions\opencart\Controllers\OpenCartImportController::class, 'linkItem'])->whereNumber('store')->name('ext.opencart.products.link_item');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store?}', [\Extensions\opencart\Controllers\OpenCartDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.opencart.dashboard');
});
