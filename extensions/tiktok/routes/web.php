<?php

use Extensions\tiktok\Controllers\TiktokSettingsController;
use Extensions\tiktok\Controllers\TiktokApiExplorerController;
use Extensions\tiktok\Controllers\TikTokOrderController;
use Extensions\tiktok\Controllers\TikTokCouponController;
use Extensions\tiktok\Controllers\TikTokListingController;
use Extensions\tiktok\Controllers\TikTokProductController;
use Extensions\tiktok\Controllers\TikTokProductGroupController;
use Illuminate\Support\Facades\Route;

// ── OAUTH SURFACES: EXEMPT FROM THE /integrations PREFIX ────────────────
//
// IA migration (2026-08-08, Task 2). Every other page in this file moved
// under /integrations/tiktok/…; these two keep their URIs permanently.
//
// TikTok Shop OAuth callback must be publicly accessible.
// TikTokController::computedRedirectUri() returns route('ext.tiktok.callback'),
// which is registered in the TikTok Shop developer console and compared
// byte-for-byte; the settings screen prints it for the operator to paste
// there. A 301 cannot rescue a value that is checked rather than followed.
// ext.tiktok.authorize is exempted with it per the migration ledger's
// pre-dispatch constraint.
Route::get('/tiktok/callback', [TiktokSettingsController::class, 'callback'])->name('ext.tiktok.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/tiktok/authorize', [TiktokSettingsController::class, 'redirectToAuth'])->name('ext.tiktok.authorize')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
});

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes — gated by 'view_tiktok|manage_tiktok' (pipe-OR, either tier
// grants access). Display GETs and pure-compute POSTs.
// Write routes — gated by 'manage_tiktok' (or 'manage_tiktok_orders').
// Anything that mutates DB or calls TikTok's API.
//
// Everything below moves under /integrations. Route NAMES are unchanged; the
// `/tiktok/…` literals inside the group are now the channel segment beneath
// that prefix, so `/tiktok/orders` reads as `/integrations/tiktok/orders`.
Route::middleware(['auth'])->prefix('channels')->group(function () {

    // ── TikTok Settings + Catalog management — READ ─────────────
    Route::group([], function () {
        // `/tiktok/settings`, not a bare `/tiktok`: /integrations/tiktok is
        // the workspace Overview, registered ahead of every extension route,
        // so a bare channel segment here would never be reached.
        Route::get('/tiktok/settings', [TiktokSettingsController::class, 'index'])->name('ext.tiktok.index');

        // Catalog browsing
        Route::get('/tiktok/categories', [TikTokProductGroupController::class, 'indexCategories'])->name('ext.tiktok.categories.index');

        // Product groups — static routes first to avoid {id} capture
        Route::get('/tiktok/product-groups/search-products', [TikTokProductGroupController::class, 'searchProducts'])->name('ext.tiktok.product-groups.searchProducts');
        Route::get('/tiktok/product-groups', [TikTokProductGroupController::class, 'index'])->name('ext.tiktok.product-groups.index');
        Route::get('/tiktok/product-groups/{id}/edit', [TikTokProductGroupController::class, 'edit'])->name('ext.tiktok.product-groups.edit');
        Route::get('/tiktok/product-groups/{id}/products', [TikTokProductGroupController::class, 'products'])->name('ext.tiktok.product-groups.products');
        // Read tier: asks TikTok Shop what it holds and prints the answer, adopting nothing.
        Route::get('/tiktok/product-groups/{id}/orphans', [TikTokProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.tiktok.product-groups.orphans');

        // Coupons (#153): a live read of the shop's coupons.
        Route::get('/tiktok/coupons', [TikTokCouponController::class, 'index'])->name('ext.tiktok.coupons.index');

        // Listings (#156): the index with TikTok's own status on load,
        // readiness before a push, and the combobox endpoints the listing
        // page reads (the door is keyed on this controller).
        Route::get('/tiktok/products', [TikTokProductController::class, 'index'])->name('ext.tiktok.products.index');
        Route::get('/tiktok/products/categories/search', [TikTokProductController::class, 'searchCategories'])->name('ext.tiktok.products.searchCategories');
        Route::get('/tiktok/products/categories/lookup', [TikTokProductController::class, 'categoryLookup'])->name('ext.tiktok.products.categoryLookup');
        Route::get('/tiktok/products/brands', [TikTokProductController::class, 'brandsSearch'])->name('ext.tiktok.products.brandsSearch');

        // The per-product page (#146): the product's derived listing
        // truth plus its discount rule. Readable by the view tier (it
        // renders read-only with no verbs); the writes are manage routes.
        Route::get('/tiktok/listings/{productId}', [TikTokListingController::class, 'edit'])
            ->whereNumber('productId')->name('ext.tiktok.listings.edit');
    });

    // ── TikTok Settings + Catalog WRITES ────────────────────────
    Route::group([], function () {
        // OAuth / token / mode. ext.tiktok.authorize is NOT here: it is one of
        // the two exempt OAuth surfaces registered above, outside this prefix.
        Route::post('/tiktok/save', [TiktokSettingsController::class, 'save'])->name('ext.tiktok.save');
        Route::post('/tiktok/toggle-mode', [TiktokSettingsController::class, 'toggleMode'])->name('ext.tiktok.toggle_mode');
        Route::post('/tiktok/token/get', [TiktokSettingsController::class, 'tokenGet'])->name('ext.tiktok.token_get');
        Route::post('/tiktok/token/refresh', [TiktokSettingsController::class, 'tokenRefresh'])->name('ext.tiktok.token_refresh');
        Route::post('/tiktok/shops', [TiktokSettingsController::class, 'getShops'])->name('ext.tiktok.shops');

        // API Explorer — calls live TikTok API; write tier.
        Route::post('/tiktok/explorer/run', [TiktokApiExplorerController::class, 'explorerRun'])->name('ext.tiktok.explorer_run');
        Route::post('/tiktok/packs/run', [TiktokApiExplorerController::class, 'packsRun'])->name('ext.tiktok.packs_run');

        // API logging admin
        Route::post('/tiktok/toggle-logging', [TiktokSettingsController::class, 'toggleLogging'])->name('ext.tiktok.toggle_logging');
        Route::delete('/tiktok/api-logs', [TiktokSettingsController::class, 'clearApiLogs'])->name('ext.tiktok.clear_api_logs');
        Route::post('/tiktok/purge-raw', [TiktokSettingsController::class, 'purgeRaw'])->name('ext.tiktok.purge_raw');

        // Status-map saves
        Route::post('/tiktok/order-status-map', [TiktokSettingsController::class, 'saveOrderStatusMap'])->name('ext.tiktok.order_status_map');
        Route::post('/tiktok/return-status-map', [TiktokSettingsController::class, 'saveReturnStatusMap'])->name('ext.tiktok.return_status_map');

        // Categories — sync from TikTok and persist
        Route::post('/tiktok/categories/sync', [TikTokProductGroupController::class, 'syncCategories'])->name('ext.tiktok.categories.sync');

        // Product groups — writes
        Route::get('/tiktok/product-groups/create', [TikTokProductGroupController::class, 'create'])->name('ext.tiktok.product-groups.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::post('/tiktok/product-groups/fetch-attributes', [TikTokProductGroupController::class, 'fetchAttributesAjax'])->name('ext.tiktok.product-groups.fetchAttributes');
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
        Route::post('/tiktok/product-groups/{id}/products/check', [TikTokProductGroupController::class, 'checkAgainstTikTok'])->whereNumber('id')->name('ext.tiktok.product-groups.check');
        Route::post('/tiktok/product-groups/{id}/push', [TikTokProductGroupController::class, 'push'])->name('ext.tiktok.product-groups.push');
        Route::post('/tiktok/product-groups/{id}/update-product', [TikTokProductGroupController::class, 'updateProduct'])->name('ext.tiktok.product-groups.updateProduct');
        Route::post('/tiktok/product-groups/{id}/push-prices', [TikTokProductGroupController::class, 'pushPrices'])->name('ext.tiktok.product-groups.pushPrices');
        Route::post('/tiktok/product-groups/{id}/push-stock', [TikTokProductGroupController::class, 'pushStock'])->name('ext.tiktok.product-groups.pushStock');

        // Listing writes (#156): the one-click push on the product's own
        // settings, Send for a selection, stock and price for one product,
        // unlink, delete, and the attribute sheet read for the listing page.
        Route::post('/tiktok/products/fetch-attributes', [TikTokProductController::class, 'fetchAttributes'])->name('ext.tiktok.products.fetchAttributes');
        Route::post('/tiktok/products/check', [TikTokProductController::class, 'checkAgainstTikTok'])->name('ext.tiktok.products.check');
        Route::post('/tiktok/products/bulk/send', [TikTokProductController::class, 'bulkSend'])->name('ext.tiktok.products.bulk_send');
        // Activate / deactivate the selection in one call, and refresh the listing status mirror on demand (the owner, 2026-08-28).
        Route::post('/tiktok/products/bulk/toggle', [TikTokListingController::class, 'bulkToggle'])->name('ext.tiktok.products.bulk_toggle');
        Route::post('/tiktok/products/refresh-status', [TikTokProductController::class, 'refreshStatus'])->name('ext.tiktok.products.refresh_status');
        Route::post('/tiktok/products/{productId}/push', [TikTokProductController::class, 'pushDirect'])->whereNumber('productId')->name('ext.tiktok.products.push_direct');
        Route::post('/tiktok/products/{productId}/push-stock', [TikTokProductController::class, 'pushStock'])->whereNumber('productId')->name('ext.tiktok.products.push_stock');
        Route::post('/tiktok/products/{productId}/push-price', [TikTokProductController::class, 'pushPrice'])->whereNumber('productId')->name('ext.tiktok.products.push_price');
        Route::post('/tiktok/products/{productId}/unlink', [TikTokProductController::class, 'unlink'])->whereNumber('productId')->name('ext.tiktok.products.unlink');
        Route::post('/tiktok/products/{productId}/delete', [TikTokProductController::class, 'deleteFromTikTok'])->whereNumber('productId')->name('ext.tiktok.products.delete');
        Route::post('/tiktok/listings/{productId}/push-update', [TikTokListingController::class, 'pushListingUpdate'])->whereNumber('productId')->name('ext.tiktok.listings.push_update');
        Route::post('/tiktok/listings/{productId}/toggle', [TikTokListingController::class, 'toggleListing'])->whereNumber('productId')->name('ext.tiktok.listings.toggle');

        // Per-product discount rule + its push (#146)
        Route::put('/tiktok/listings/{productId}', [TikTokListingController::class, 'update'])->whereNumber('productId')->name('ext.tiktok.listings.update');
        Route::post('/tiktok/listings/{productId}/push-discount', [TikTokListingController::class, 'pushDiscount'])->whereNumber('productId')->name('ext.tiktok.listings.push_discount');

        // Coupon writes (#153): create, deactivate. Pass-throughs; TikTok owns the object.
        Route::post('/tiktok/coupons', [TikTokCouponController::class, 'store'])->name('ext.tiktok.coupons.store');
        Route::post('/tiktok/coupons/{couponId}/deactivate', [TikTokCouponController::class, 'deactivate'])->where('couponId', '[A-Za-z0-9_-]+')->name('ext.tiktok.coupons.deactivate');
    });

    // ── TikTok Orders — READ (browse, show, AWB/tracking download) ──
    Route::group([], function () {
        Route::get('/tiktok/orders', [TikTokOrderController::class, 'index'])->name('ext.tiktok.orders.index');
        Route::get('/tiktok/orders/returns', [TikTokOrderController::class, 'returns'])->name('ext.tiktok.orders.returns');
        Route::get('/tiktok/orders/{id}/show', [TikTokOrderController::class, 'show'])->name('ext.tiktok.orders.show');
        Route::get('/tiktok/orders/{id}/awb', [TikTokOrderController::class, 'awbPdf'])->name('ext.tiktok.orders.awb');
        Route::get('/tiktok/orders/{id}/tracking', [TikTokOrderController::class, 'tracking'])->name('ext.tiktok.orders.tracking');
    });

    // ── TikTok Orders — WRITE (sync, ship) ──────────────────────
    Route::group([], function () {
        Route::post('/tiktok/orders/fetch', [TikTokOrderController::class, 'fetch'])->name('ext.tiktok.orders.fetch');
        Route::post('/tiktok/orders/returns/fetch', [TikTokOrderController::class, 'fetchReturns'])->name('ext.tiktok.orders.fetch_returns');
        Route::post('/tiktok/orders/update-statuses', [TikTokOrderController::class, 'updateStatuses'])->name('ext.tiktok.orders.updateStatuses');
        Route::post('/tiktok/orders/{id}/ship', [TikTokOrderController::class, 'shipOrder'])->name('ext.tiktok.orders.ship');
    });
});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/tiktok was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Tiktok operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// tiktok/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/tiktok/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    // No {store?} tail: this channel is single-store, and an optional
    // 'store' parameter is exactly what a GLOBAL URL default fills - the
    // moment Shopee (multi-store S4, #194) registered a default store id,
    // every generated link here became /channels/tiktok/1 and 404d. A
    // store-suffixed URL still 404s, now by matching no route at all.
    Route::get('/tiktok', [\Extensions\tiktok\Controllers\TiktokDashboardController::class, 'show'])
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.tiktok.dashboard');
});
