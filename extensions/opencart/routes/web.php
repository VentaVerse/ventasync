<?php

use Extensions\opencart\Controllers\OpenCartController;
use Extensions\opencart\Controllers\OpenCartProductGroupController;
use Extensions\opencart\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'perm:manage_opencart'])->group(function () {
    // Per-store settings (canonical entry from module page or sidebar).
    Route::get('/opencart/settings/{store}', [OpenCartController::class, 'showSettings'])->name('ext.opencart.settings.show');

    // "Add New Store" modal target — minimal create with name + base_url only.
    Route::post('/opencart/stores', [OpenCartController::class, 'createStore'])->name('ext.opencart.stores.store');

    Route::get('/opencart', [OpenCartController::class, 'index'])->name('ext.opencart.index');
    Route::post('/opencart/save', [OpenCartController::class, 'save'])->name('ext.opencart.save');
    Route::delete('/opencart/{id}', [OpenCartController::class, 'destroy'])->name('ext.opencart.destroy');
    Route::post('/opencart/test', [OpenCartController::class, 'testConnection'])->name('ext.opencart.test');
    Route::post('/opencart/sync', [OpenCartController::class, 'syncNow'])->name('ext.opencart.sync');
    Route::post('/opencart/sync-date', [OpenCartController::class, 'saveSyncDate'])->name('ext.opencart.sync_date');
    Route::post('/opencart/push', [OpenCartController::class, 'pushNow'])->name('ext.opencart.push');
    Route::post('/opencart/push-qty', [OpenCartController::class, 'pushQtyNow'])->name('ext.opencart.push_qty');
    Route::post('/opencart/verify-password', [OpenCartController::class, 'verifyPassword'])->name('ext.opencart.verify_password');
    Route::post('/opencart/fetch-oc-statuses', [OpenCartController::class, 'fetchOcStatuses'])->name('ext.opencart.fetch_oc_statuses');
    Route::post('/opencart/save-status-map', [OpenCartController::class, 'saveOrderStatusMap'])->name('ext.opencart.save_status_map');
    Route::post('/opencart/save-review-settings', [OpenCartController::class, 'saveReviewSettings'])->name('ext.opencart.save_review_settings');

    // Single product push (from product group products page)
    Route::post('/opencart/{store}/products/{product}/push', [OpenCartController::class, 'pushProduct'])->name('ext.opencart.products.push');

    // OpenCart per-store product groups
    Route::get('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'index'])->name('ext.opencart.product-groups.index');
    Route::get('/opencart/{store}/product-groups/create', [OpenCartProductGroupController::class, 'create'])->name('ext.opencart.product-groups.create');
    Route::post('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'store'])->name('ext.opencart.product-groups.store');
    Route::get('/opencart/{store}/product-groups/{group}/edit', [OpenCartProductGroupController::class, 'edit'])->name('ext.opencart.product-groups.edit');
    Route::put('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'update'])->name('ext.opencart.product-groups.update');
    Route::delete('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'destroy'])->name('ext.opencart.product-groups.destroy');
    Route::get('/opencart/{store}/product-groups/{group}/products', [OpenCartProductGroupController::class, 'products'])->name('ext.opencart.product-groups.products');
    Route::post('/opencart/{store}/product-groups/{group}/push', [OpenCartProductGroupController::class, 'push'])->name('ext.opencart.product-groups.push');
    Route::post('/opencart/{store}/product-groups/{group}/add-products', [OpenCartProductGroupController::class, 'addProducts'])->name('ext.opencart.product-groups.addProducts');
    Route::delete('/opencart/{store}/product-groups/{group}/products/{product}', [OpenCartProductGroupController::class, 'removeProduct'])->name('ext.opencart.product-groups.removeProduct');
    Route::get('/opencart/{store}/product-groups/search-products', [OpenCartProductGroupController::class, 'searchProducts'])->name('ext.opencart.product-groups.searchProducts');
});

// OpenCart Orders (separate permission from OpenCart settings/product groups)
Route::middleware(['auth', 'perm:manage_opencart_orders'])->group(function () {
    Route::get('/opencart/{store}/orders', [\Extensions\opencart\Controllers\OpenCartOrderController::class, 'index'])->name('ext.opencart.orders.index');
});

// Reviews (synced from marketplaces, pushed to OpenCart)
Route::middleware(['auth', 'perm:manage_reviews'])->group(function () {
    Route::get('/reviews', [ReviewController::class, 'index'])->name('ext.opencart.reviews.index');
    Route::get('/reviews/{id}', [ReviewController::class, 'show'])->name('ext.opencart.reviews.show')->whereNumber('id');
    Route::post('/reviews/{id}/push', [ReviewController::class, 'pushToOpenCart'])->name('ext.opencart.reviews.push')->whereNumber('id');
    Route::post('/reviews/{id}/skip', [ReviewController::class, 'skip'])->name('ext.opencart.reviews.skip')->whereNumber('id');
    Route::post('/reviews/bulk-push', [ReviewController::class, 'bulkPush'])->name('ext.opencart.reviews.bulk_push');
    Route::post('/reviews/fetch', [ReviewController::class, 'fetch'])->name('ext.opencart.reviews.fetch');
    Route::post('/reviews/push-all', [ReviewController::class, 'pushAll'])->name('ext.opencart.reviews.push_all');
    Route::post('/reviews/delete-all', [ReviewController::class, 'deleteAll'])->name('ext.opencart.reviews.delete_all');
});
