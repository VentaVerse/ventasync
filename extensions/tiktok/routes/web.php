<?php

use Extensions\tiktok\Controllers\TikTokController;
use Extensions\tiktok\Controllers\TikTokOrderController;
use Extensions\tiktok\Controllers\TikTokProductGroupController;
use Illuminate\Support\Facades\Route;

// TikTok Shop OAuth callback must be publicly accessible.
Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('ext.tiktok.callback');

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes — gated by 'view_tiktok|manage_tiktok' (pipe-OR, either tier
// grants access). Display GETs and pure-compute POSTs.
// Write routes — gated by 'manage_tiktok' (or 'manage_tiktok_orders').
// Anything that mutates DB or calls TikTok's API.
Route::middleware(['auth'])->group(function () {

    // ── TikTok Settings + Catalog management — READ ─────────────
    Route::middleware(['perm:view_tiktok|manage_tiktok'])->group(function () {
        Route::get('/tiktok', [TikTokController::class, 'index'])->name('ext.tiktok.index');

        // Catalog browsing
        Route::get('/tiktok/categories', [TikTokProductGroupController::class, 'indexCategories'])->name('ext.tiktok.categories.index');

        // Product groups — static routes first to avoid {id} capture
        Route::get('/tiktok/product-groups/search-products', [TikTokProductGroupController::class, 'searchProducts'])->name('ext.tiktok.product-groups.searchProducts');
        Route::get('/tiktok/product-groups', [TikTokProductGroupController::class, 'index'])->name('ext.tiktok.product-groups.index');
        Route::get('/tiktok/product-groups/{id}/edit', [TikTokProductGroupController::class, 'edit'])->name('ext.tiktok.product-groups.edit');
        Route::get('/tiktok/product-groups/{id}/products', [TikTokProductGroupController::class, 'products'])->name('ext.tiktok.product-groups.products');
    });

    // ── TikTok Settings + Catalog WRITES ────────────────────────
    Route::middleware(['perm:manage_tiktok'])->group(function () {
        // OAuth / token / mode
        Route::get('/tiktok/authorize', [TikTokController::class, 'redirectToAuth'])->name('ext.tiktok.authorize');
        Route::post('/tiktok/save', [TikTokController::class, 'save'])->name('ext.tiktok.save');
        Route::post('/tiktok/toggle-mode', [TikTokController::class, 'toggleMode'])->name('ext.tiktok.toggle_mode');
        Route::post('/tiktok/token/get', [TikTokController::class, 'tokenGet'])->name('ext.tiktok.token_get');
        Route::post('/tiktok/token/refresh', [TikTokController::class, 'tokenRefresh'])->name('ext.tiktok.token_refresh');
        Route::post('/tiktok/shops', [TikTokController::class, 'getShops'])->name('ext.tiktok.shops');

        // API Explorer — calls live TikTok API; write tier.
        Route::post('/tiktok/explorer/run', [TikTokController::class, 'explorerRun'])->name('ext.tiktok.explorer_run');
        Route::post('/tiktok/packs/run', [TikTokController::class, 'packsRun'])->name('ext.tiktok.packs_run');

        // API logging admin
        Route::post('/tiktok/toggle-logging', [TikTokController::class, 'toggleLogging'])->name('ext.tiktok.toggle_logging');
        Route::delete('/tiktok/api-logs', [TikTokController::class, 'clearApiLogs'])->name('ext.tiktok.clear_api_logs');
        Route::post('/tiktok/purge-raw', [TikTokController::class, 'purgeRaw'])->name('ext.tiktok.purge_raw');

        // Status-map saves
        Route::post('/tiktok/order-status-map', [TikTokController::class, 'saveOrderStatusMap'])->name('ext.tiktok.order_status_map');

        // Categories — sync from TikTok and persist
        Route::post('/tiktok/categories/sync', [TikTokProductGroupController::class, 'syncCategories'])->name('ext.tiktok.categories.sync');

        // Product groups — writes
        Route::get('/tiktok/product-groups/create', [TikTokProductGroupController::class, 'create'])->name('ext.tiktok.product-groups.create');
        Route::post('/tiktok/product-groups', [TikTokProductGroupController::class, 'store'])->name('ext.tiktok.product-groups.store');
        Route::put('/tiktok/product-groups/{id}', [TikTokProductGroupController::class, 'update'])->name('ext.tiktok.product-groups.update');
        Route::delete('/tiktok/product-groups/{id}', [TikTokProductGroupController::class, 'destroy'])->name('ext.tiktok.product-groups.destroy');
        Route::post('/tiktok/product-groups/{id}/add-products', [TikTokProductGroupController::class, 'addProducts'])->name('ext.tiktok.product-groups.addProducts');
        Route::post('/tiktok/product-groups/{id}/products/{product}/sync-id', [TikTokProductGroupController::class, 'syncId'])->name('ext.tiktok.product-groups.syncId');
        Route::post('/tiktok/product-groups/{id}/products/{product}/unlink', [TikTokProductGroupController::class, 'unlinkProduct'])->name('ext.tiktok.product-groups.unlinkProduct');
        Route::post('/tiktok/product-groups/{id}/products/{product}/link', [TikTokProductGroupController::class, 'linkProduct'])->name('ext.tiktok.product-groups.linkProduct');
        Route::delete('/tiktok/product-groups/{id}/products/{product}', [TikTokProductGroupController::class, 'removeProduct'])->name('ext.tiktok.product-groups.removeProduct');
        Route::post('/tiktok/product-groups/{id}/mass-remove', [TikTokProductGroupController::class, 'massRemove'])->name('ext.tiktok.product-groups.massRemove');
        Route::post('/tiktok/product-groups/{id}/delete-from-tiktok', [TikTokProductGroupController::class, 'deleteFromTikTok'])->name('ext.tiktok.product-groups.deleteFromTikTok');
        Route::post('/tiktok/product-groups/{id}/push', [TikTokProductGroupController::class, 'push'])->name('ext.tiktok.product-groups.push');
        Route::post('/tiktok/product-groups/{id}/update-product', [TikTokProductGroupController::class, 'updateProduct'])->name('ext.tiktok.product-groups.updateProduct');
        Route::post('/tiktok/product-groups/{id}/push-prices', [TikTokProductGroupController::class, 'pushPrices'])->name('ext.tiktok.product-groups.pushPrices');
        Route::post('/tiktok/product-groups/{id}/push-stock', [TikTokProductGroupController::class, 'pushStock'])->name('ext.tiktok.product-groups.pushStock');
    });

    // ── TikTok Orders — READ (browse, show, AWB/tracking download) ──
    Route::middleware(['perm:view_tiktok_orders|manage_tiktok_orders'])->group(function () {
        Route::get('/tiktok/orders', [TikTokOrderController::class, 'index'])->name('ext.tiktok.orders.index');
        Route::get('/tiktok/orders/{id}/show', [TikTokOrderController::class, 'show'])->name('ext.tiktok.orders.show');
        Route::get('/tiktok/orders/{id}/awb', [TikTokOrderController::class, 'awbPdf'])->name('ext.tiktok.orders.awb');
        Route::get('/tiktok/orders/{id}/tracking', [TikTokOrderController::class, 'tracking'])->name('ext.tiktok.orders.tracking');
    });

    // ── TikTok Orders — WRITE (sync, ship) ──────────────────────
    Route::middleware(['perm:manage_tiktok_orders'])->group(function () {
        Route::post('/tiktok/orders/fetch', [TikTokOrderController::class, 'fetch'])->name('ext.tiktok.orders.fetch');
        Route::post('/tiktok/orders/update-statuses', [TikTokOrderController::class, 'updateStatuses'])->name('ext.tiktok.orders.updateStatuses');
        Route::post('/tiktok/orders/{id}/ship', [TikTokOrderController::class, 'shipOrder'])->name('ext.tiktok.orders.ship');
    });
});
