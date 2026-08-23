<?php

use Extensions\opencart\Controllers\OpenCartController;
use Extensions\opencart\Controllers\OpenCartProductGroupController;
use Extensions\opencart\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes — gated by 'view_*|manage_*'. Display GETs.
// Write routes — gated by 'manage_*'. Any mutation, sync, push, or marketplace
// state change.

// IA migration (2026-08-08, Task 2), spec decision 3. OpenCart is multi-store,
// so its URIs use SLASH grammar under the workspace root: the store is a path
// segment directly beneath the channel. /integrations/opencart/2/orders reads
// as "OpenCart, store 2, orders".
//
// Two shapes changed beyond gaining the prefix, matching Venta:
//   /opencart/settings/{store}  ->  /integrations/opencart/{store}/settings
//   /opencart                   ->  /integrations/opencart/settings
//        a bare /integrations/opencart is the workspace Overview, registered
//        in routes/web.php ahead of every extension route, so this redirect-
//        to-first-store fallback needs a segment of its own to stay reachable.
//
// Route NAMES are unchanged. OpenCart has no OAuth surface (it authenticates
// with a stored API key), so nothing here is exempt. The Reviews block at the
// bottom of this file is NOT part of this prefix: Task 1 already placed it
// under /catalog, where its nav group puts it.
//
// ── OpenCart Settings + Catalog — READ ──────────────────────────
Route::middleware(['auth', 'perm:view_opencart|manage_opencart'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store}/settings', [OpenCartController::class, 'showSettings'])->name('ext.opencart.settings.show');
    Route::get('/opencart/settings', [OpenCartController::class, 'index'])->name('ext.opencart.index');

    // Product groups — read-only list + edit-form RENDER
    Route::get('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'index'])->name('ext.opencart.product-groups.index');
    Route::get('/opencart/{store}/product-groups/{group}/edit', [OpenCartProductGroupController::class, 'edit'])->name('ext.opencart.product-groups.edit');
    Route::get('/opencart/{store}/product-groups/{group}/products', [OpenCartProductGroupController::class, 'products'])->name('ext.opencart.product-groups.products');
    Route::get('/opencart/{store}/product-groups/search-products', [OpenCartProductGroupController::class, 'searchProducts'])->name('ext.opencart.product-groups.searchProducts');
});

// ── OpenCart Settings + Catalog — WRITES ────────────────────────
Route::middleware(['auth', 'perm:manage_opencart'])->prefix('channels')->group(function () {
    // "Add New Store" modal target — minimal create with name + base_url only.
    Route::post('/opencart/stores', [OpenCartController::class, 'createStore'])->name('ext.opencart.stores.store');

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

    // Product Groups — writes
    Route::get('/opencart/{store}/product-groups/create', [OpenCartProductGroupController::class, 'create'])->name('ext.opencart.product-groups.create');
    Route::post('/opencart/{store}/product-groups', [OpenCartProductGroupController::class, 'store'])->name('ext.opencart.product-groups.store');
    Route::put('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'update'])->name('ext.opencart.product-groups.update');
    Route::delete('/opencart/{store}/product-groups/{group}', [OpenCartProductGroupController::class, 'destroy'])->name('ext.opencart.product-groups.destroy');
    Route::post('/opencart/{store}/product-groups/{group}/push', [OpenCartProductGroupController::class, 'push'])->name('ext.opencart.product-groups.push');
    Route::post('/opencart/{store}/product-groups/{group}/add-products', [OpenCartProductGroupController::class, 'addProducts'])->name('ext.opencart.product-groups.addProducts');
    Route::delete('/opencart/{store}/product-groups/{group}/products/{product}', [OpenCartProductGroupController::class, 'removeProduct'])->name('ext.opencart.product-groups.removeProduct');
});

// ── OpenCart Orders — READ ──────────────────────────────────────
Route::middleware(['auth', 'perm:view_opencart_orders|manage_opencart_orders'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store}/orders', [\Extensions\opencart\Controllers\OpenCartOrderController::class, 'index'])->name('ext.opencart.orders.index');
});

// ── OpenCart Orders — WRITES (none defined currently) ───────────
// No write routes exist yet for OpenCart orders.

// ── Reviews — READ ──────────────────────────────────────────────
// IA migration (2026-08-08, Task 1): reviews sit in the Catalogue nav
// group, so their URI nests under /catalog/reviews (not /opencart) even
// though the controller stays in this extension. Route names unchanged;
// old /reviews/* URIs 301 via routes/redirects.php.
Route::middleware(['auth', 'perm:view_reviews|manage_reviews'])->group(function () {
    Route::get('/catalog/reviews', [ReviewController::class, 'index'])->name('ext.opencart.reviews.index');
    Route::get('/catalog/reviews/{id}', [ReviewController::class, 'show'])->name('ext.opencart.reviews.show')->whereNumber('id');
});

// ── Reviews — WRITES (push, fetch, delete) ──────────────────────
Route::middleware(['auth', 'perm:manage_reviews'])->group(function () {
    Route::post('/catalog/reviews/{id}/push', [ReviewController::class, 'pushToOpenCart'])->name('ext.opencart.reviews.push')->whereNumber('id');
    Route::post('/catalog/reviews/{id}/skip', [ReviewController::class, 'skip'])->name('ext.opencart.reviews.skip')->whereNumber('id');
    Route::post('/catalog/reviews/bulk-push', [ReviewController::class, 'bulkPush'])->name('ext.opencart.reviews.bulk_push');
    Route::post('/catalog/reviews/fetch', [ReviewController::class, 'fetch'])->name('ext.opencart.reviews.fetch');
    Route::post('/catalog/reviews/push-all', [ReviewController::class, 'pushAll'])->name('ext.opencart.reviews.push_all');
    Route::post('/catalog/reviews/delete-all', [ReviewController::class, 'deleteAll'])->name('ext.opencart.reviews.delete_all');
});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/opencart was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every OpenCart operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// opencart/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/opencart/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/opencart/{store?}', [\Extensions\opencart\Controllers\OpenCartDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.opencart.dashboard');
});
