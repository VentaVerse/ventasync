<?php

use Extensions\venta\Controllers\VentaController;
use Extensions\venta\Controllers\VentaProductGroupController;
use Extensions\venta\Controllers\VentaOrderController;
use Extensions\venta\Controllers\VentaReviewController;
use Illuminate\Support\Facades\Route;

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes — gated by 'view_venta|manage_venta' (pipe-OR, either tier
// grants access). Display GETs only.
// Write routes — gated by 'manage_venta' (or 'manage_venta_orders').

// IA migration (2026-08-08, Task 2), spec decision 3. Venta is multi-store, so
// its URIs use SLASH grammar under the workspace root: the store is a path
// segment directly beneath the channel, and everything for that store hangs
// off it. /integrations/venta/2/orders reads as "Venta, store 2, orders".
//
// Two shapes changed beyond gaining the prefix:
//   /venta/settings/{store}  ->  /integrations/venta/{store}/settings
//        the store scopes the page, so it belongs ahead of what it scopes;
//        the old order put the noun before the thing it belonged to.
//   /venta                   ->  /integrations/venta/settings
//        a bare /integrations/venta is the workspace Overview, registered in
//        routes/web.php ahead of every extension route, so this redirect-to-
//        first-store fallback needs a segment of its own to stay reachable.
//
// Route NAMES are unchanged throughout. Venta has no OAuth surface: it
// authenticates with a stored API token, so nothing here is exempt.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    // Settings (renders the screen; per-store config is loaded into a form)
    Route::get('/venta/{store}/settings', [VentaController::class, 'showSettings'])->name('ext.venta.settings.show');

    // Legacy / fallback (redirect to first enabled store) — pure navigation
    Route::get('/venta/settings', [VentaController::class, 'index'])->name('ext.venta.index');

    // Product Groups — read-only list + edit-form RENDER (GETs)
    Route::get('/venta/{store}/product-groups', [VentaProductGroupController::class, 'index'])->name('ext.venta.product-groups.index');
    Route::get('/venta/{store}/product-groups/{group}/edit', [VentaProductGroupController::class, 'edit'])->name('ext.venta.product-groups.edit');
    Route::get('/venta/{store}/product-groups/{group}/products', [VentaProductGroupController::class, 'products'])->name('ext.venta.product-groups.products');
    // Read tier, beside the products list it mirrors, NOT with the writes.
    // It only asks Venta what it holds and prints the answer; it adopts
    // nothing and deletes nothing. Seeing that the two sides have drifted is
    // exactly what a view-only operator is for - they are the ones who notice
    // and escalate - and putting it behind manage_venta would have hidden the
    // report from the people most likely to read it.
    Route::get('/venta/{store}/product-groups/{group}/orphans', [VentaProductGroupController::class, 'orphans'])->name('ext.venta.product-groups.orphans');

    // Product search (JSON API) — read-only lookup
    Route::get('/venta/{store}/product-groups/search-products', [VentaProductGroupController::class, 'searchProducts'])->name('ext.venta.product-groups.searchProducts');
});

// ── Venta Settings + Catalog — WRITES ───────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    // "Add New Store" modal target — creates a row with name + base_url only.
    Route::post('/venta/stores', [VentaController::class, 'createStore'])->name('ext.venta.stores.store');

    // Per-store settings writes
    Route::post('/venta/save', [VentaController::class, 'save'])->name('ext.venta.save');
    Route::delete('/venta/{id}', [VentaController::class, 'destroy'])->name('ext.venta.destroy');
    Route::post('/venta/test', [VentaController::class, 'testConnection'])->name('ext.venta.test');
    Route::post('/venta/save-status-map', [VentaController::class, 'saveOrderStatusMap'])->name('ext.venta.save_status_map');
    Route::post('/venta/{id}/toggle-api-logging', [VentaController::class, 'toggleApiLogging'])->name('ext.venta.toggle_api_logging');
    Route::delete('/venta/{id}/api-logs', [VentaController::class, 'clearApiLogs'])->name('ext.venta.clear_api_logs');
    Route::post('/venta/fetch-statuses', [VentaController::class, 'fetchVentaStatuses'])->name('ext.venta.fetch_statuses');

    // Fetch categories/brands from Venta API (AJAX) — calls the marketplace API
    Route::post('/venta/fetch-categories', [VentaController::class, 'fetchCategories'])->name('ext.venta.fetch_categories');
    Route::post('/venta/fetch-brands', [VentaController::class, 'fetchBrands'])->name('ext.venta.fetch_brands');

    // Product Groups — writes (create, update, destroy, push)
    Route::get('/venta/{store}/product-groups/create', [VentaProductGroupController::class, 'create'])->name('ext.venta.product-groups.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
    Route::post('/venta/{store}/product-groups', [VentaProductGroupController::class, 'store'])->name('ext.venta.product-groups.store');
    Route::put('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'update'])->name('ext.venta.product-groups.update');
    Route::delete('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'destroy'])->name('ext.venta.product-groups.destroy');

    Route::post('/venta/{store}/product-groups/{group}/push', [VentaProductGroupController::class, 'pushProducts'])->name('ext.venta.product-groups.push');
    Route::post('/venta/{store}/product-groups/{group}/push-stock', [VentaProductGroupController::class, 'pushStock'])->name('ext.venta.product-groups.push-stock');
    Route::post('/venta/{store}/product-groups/{group}/push-prices', [VentaProductGroupController::class, 'pushPrices'])->name('ext.venta.product-groups.push-prices');
    Route::post('/venta/{store}/product-groups/{group}/add-products', [VentaProductGroupController::class, 'addProducts'])->name('ext.venta.product-groups.addProducts');
    Route::post('/venta/{store}/product-groups/{group}/mass-remove', [VentaProductGroupController::class, 'massRemove'])->name('ext.venta.product-groups.mass-remove');
    // Renamed from sync-ids/syncIds. The old name described the mechanism
    // (write ids into rows); this one describes what the operator is asking
    // for, and the behaviour changed with it: it now examines products that
    // are already linked, which is the only way a stale link can be found.
    Route::post('/venta/{store}/product-groups/{group}/check', [VentaProductGroupController::class, 'checkAgainstVenta'])->name('ext.venta.product-groups.check');
    Route::delete('/venta/{store}/product-groups/{group}/products/{product}', [VentaProductGroupController::class, 'removeProduct'])->name('ext.venta.product-groups.removeProduct');

    // Per-product actions
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/sync-id', [VentaProductGroupController::class, 'syncId'])->name('ext.venta.product-groups.sync-id');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/unlink', [VentaProductGroupController::class, 'unlinkProduct'])->name('ext.venta.product-groups.unlink');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.deleteFromVenta');
    // The bulk form of the line above, posting ids[] instead of naming one
    // product in the path. Same controller method, same permission; Venta was
    // the only channel of the four that could not do this.
    Route::post('/venta/{store}/product-groups/{group}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.bulk-delete-from-venta');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/link', [VentaProductGroupController::class, 'linkProduct'])->name('ext.venta.product-groups.link');
});

// ── Venta Orders — READ ─────────────────────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store}/orders', [VentaOrderController::class, 'index'])->name('ext.venta.orders.index');
});

// ── Venta Orders — WRITES ───────────────────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/{store}/orders/fetch', [VentaOrderController::class, 'fetch'])->name('ext.venta.orders.fetch');
    Route::delete('/venta/{store}/orders/{order}', [VentaOrderController::class, 'destroy'])->name('ext.venta.orders.destroy');
    Route::post('/venta/{store}/orders/bulk-delete', [VentaOrderController::class, 'bulkDelete'])->name('ext.venta.orders.bulk_delete');
});

// Venta Reviews (push marketplace reviews to Venta stores) — write tier
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/reviews/{id}/push', [VentaReviewController::class, 'push'])->name('ext.venta.reviews.push')->whereNumber('id');
    Route::post('/venta/reviews/push-all', [VentaReviewController::class, 'pushAll'])->name('ext.venta.reviews.push_all');
});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/venta was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Venta operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// venta/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/venta/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store?}', [\Extensions\venta\Controllers\VentaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.venta.dashboard');
});
