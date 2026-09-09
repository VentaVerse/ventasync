<?php

use Extensions\tiktok\Controllers\TikTokDescriptionTemplateController;
use Extensions\tiktok\Controllers\TiktokSettingsController;
use Extensions\tiktok\Controllers\TiktokApiExplorerController;
use Extensions\tiktok\Controllers\TikTokOrderController;
use Extensions\tiktok\Controllers\TikTokCouponController;
use Extensions\tiktok\Controllers\TikTokListingController;
use Extensions\tiktok\Controllers\TikTokProductController;
use Extensions\tiktok\Controllers\TikTokProductGroupController;
use Illuminate\Support\Facades\Route;

// ── OAUTH SURFACES: STORELESS, EXEMPT FROM THE {store} SEGMENT ───────────
//
// Multi-store (the owner 2026-09-05): every TikTok page below now lives under
// /channels/tiktok/{store}/… . These two do NOT gain the segment.
//
// The TikTok Shop OAuth callback is one fixed URL for the whole install - it
// is registered byte-for-byte in the TikTok Shop developer console and the
// settings screen prints it to paste there, so a {store} in its path would
// break OAuth for every seller. The store the grant belongs to therefore
// rides the OAuth `state` parameter, HMAC-signed so a session-less callback
// can still trust it; callback() reads the store id out of it. authorize is
// exempted with it and learns its store from a ?store= query.
Route::get('/tiktok/callback', [TiktokSettingsController::class, 'callback'])->name('ext.tiktok.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/tiktok/authorize', [TiktokSettingsController::class, 'redirectToAuth'])->name('ext.tiktok.authorize')
        // A form/action page is a MANAGE surface even though it is a GET.
        ->defaults('permission_tier', 'manage');
});

// ── Tier-aware permission map ───────────────────────────────────────────
//
// Read routes — gated by 'view_tiktok|manage_tiktok' (pipe-OR). Write routes —
// 'manage_tiktok' (or 'manage_tiktok_orders'). Anything that mutates DB or
// calls TikTok's API is a write.
//
// Multi-store (the owner 2026-09-05): every page moves under
// /channels/tiktok/{store}/… . ResolveTikTokStore binds the store the segment
// names, so TikTokSetting::defaultStore() and every route() call on the page
// answer with it - controllers keep reading defaultStore(), and views need no
// edit. Route NAMES are unchanged. The {store} tail is digits-only.
Route::middleware(['auth', \Extensions\tiktok\Http\ResolveTikTokStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    // ── TikTok Settings + Catalog management — READ ─────────────
    Route::group([], function () {
        // Creating a store is the one settings write with no store to stand in
        // yet: the Integrations board's Add store modal posts a name here.
        Route::post('/tiktok/stores', [TiktokSettingsController::class, 'createStore'])->name('ext.tiktok.stores.store');

        // The per-store settings page (renders the screen, no writes).
        Route::get('/tiktok/{store}/settings', [TiktokSettingsController::class, 'index'])->name('ext.tiktok.index');

        // Catalog browsing
        Route::get('/tiktok/{store}/categories', [TikTokProductGroupController::class, 'indexCategories'])->name('ext.tiktok.categories.index');

        // Product groups. ext.tiktok.product-groups.searchProducts (the
        // whole-catalogue picker behind the dual list and "Add a product by
        // name") retired with both on 2026-09-05 (#201): a group only holds
        // what THIS STORE carries, and the panel search below is that.
        Route::get('/tiktok/{store}/product-groups', [TikTokProductGroupController::class, 'index'])->name('ext.tiktok.product-groups.index');
        
        /* Description templates (the owner, 2026-09-07): the store's own words a
           listing puts above or below the product description. Read on the view
           tier; the writes are manage. The + on a listing page posts to store. */
        Route::get('/tiktok/{store}/description-templates', [TikTokDescriptionTemplateController::class, 'index'])->name('ext.tiktok.description-templates.index');
        Route::post('/tiktok/{store}/description-templates', [TikTokDescriptionTemplateController::class, 'store'])->name('ext.tiktok.description-templates.store')->defaults('permission_tier', 'manage');
        Route::put('/tiktok/{store}/description-templates/{template}', [TikTokDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.tiktok.description-templates.update')->defaults('permission_tier', 'manage');
        Route::delete('/tiktok/{store}/description-templates/{template}', [TikTokDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.tiktok.description-templates.destroy')->defaults('permission_tier', 'manage');
        Route::get('/tiktok/{store}/product-groups/{id}/edit', [TikTokProductGroupController::class, 'edit'])->name('ext.tiktok.product-groups.edit');
        Route::get('/tiktok/{store}/product-groups/{id}/products', [TikTokProductGroupController::class, 'products'])->name('ext.tiktok.product-groups.products');
        // The group page's Add products panel search (#201, Draft C): this
        // store's products, flagged in-group or owned elsewhere. A read.
        Route::get('/tiktok/{store}/product-groups/{id}/products/search', [TikTokProductGroupController::class, 'productSearch'])->whereNumber('id')->name('ext.tiktok.product-groups.productSearch');
        // Read tier: asks TikTok Shop what it holds and prints the answer, adopting nothing.
        Route::get('/tiktok/{store}/product-groups/{id}/orphans', [TikTokProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.tiktok.product-groups.orphans');

        // Coupons (#153): a live read of the shop's coupons.
        Route::get('/tiktok/{store}/coupons', [TikTokCouponController::class, 'index'])->name('ext.tiktok.coupons.index');

        // Listings (#156): the index with TikTok's own status on load, the
        // combobox endpoints the listing page reads.
        Route::get('/tiktok/{store}/products', [TikTokProductController::class, 'index'])->name('ext.tiktok.products.index');
        // The Add products panel's catalogue search (#201, Draft C): a read
        // that lives beside the adds it feeds, in the read tier.
        Route::get('/tiktok/{store}/products/catalogue-search', [TikTokProductController::class, 'catalogueSearch'])->name('ext.tiktok.products.catalogue_search');
        Route::get('/tiktok/{store}/products/import', [TikTokProductController::class, 'importPage'])->name('ext.tiktok.products.import');
        Route::get('/tiktok/{store}/products/categories/search', [TikTokProductController::class, 'searchCategories'])->name('ext.tiktok.products.searchCategories');
        Route::get('/tiktok/{store}/products/categories/lookup', [TikTokProductController::class, 'categoryLookup'])->name('ext.tiktok.products.categoryLookup');
        Route::get('/tiktok/{store}/products/brands', [TikTokProductController::class, 'brandsSearch'])->name('ext.tiktok.products.brandsSearch');

        // The per-product page (#146): readable by the view tier (renders
        // read-only with no verbs); the writes are manage routes.
        Route::get('/tiktok/{store}/listings/{productId}', [TikTokListingController::class, 'edit'])
            ->whereNumber('productId')->name('ext.tiktok.listings.edit');
    });

    // ── TikTok Settings + Catalog WRITES ────────────────────────
    Route::group([], function () {
        // OAuth / token / mode. ext.tiktok.authorize is NOT here: it is one of
        // the two exempt OAuth surfaces registered above, outside this prefix.
        Route::post('/tiktok/{store}/save', [TiktokSettingsController::class, 'save'])->name('ext.tiktok.save');
        Route::post('/tiktok/{store}/toggle-mode', [TiktokSettingsController::class, 'toggleMode'])->name('ext.tiktok.toggle_mode');
        Route::post('/tiktok/{store}/token/get', [TiktokSettingsController::class, 'tokenGet'])->name('ext.tiktok.token_get');
        Route::post('/tiktok/{store}/token/refresh', [TiktokSettingsController::class, 'tokenRefresh'])->name('ext.tiktok.token_refresh');
        Route::post('/tiktok/{store}/shops', [TiktokSettingsController::class, 'getShops'])->name('ext.tiktok.shops');

        // API Explorer — calls live TikTok API; write tier.
        Route::post('/tiktok/{store}/explorer/run', [TiktokApiExplorerController::class, 'explorerRun'])->name('ext.tiktok.explorer_run');
        Route::post('/tiktok/{store}/packs/run', [TiktokApiExplorerController::class, 'packsRun'])->name('ext.tiktok.packs_run');

        // API logging admin
        Route::post('/tiktok/{store}/api-log-mode', [TiktokSettingsController::class, 'setApiLogMode'])->name('ext.tiktok.api_log_mode');
        Route::delete('/tiktok/{store}/api-logs', [TiktokSettingsController::class, 'clearApiLogs'])->name('ext.tiktok.clear_api_logs');
        Route::post('/tiktok/{store}/purge-raw', [TiktokSettingsController::class, 'purgeRaw'])->name('ext.tiktok.purge_raw');
        // Hard delete of a store (the owner, 2026-09-05): the typed name confirms;
        // everything the store owns goes, core orders stay under its name.
        Route::post('/tiktok/{store}/delete', [TiktokSettingsController::class, 'destroyStore'])->name('ext.tiktok.stores.destroy');
        // Set up the store: the shop then the categories, one step per call, behind the setup popup.
        Route::post('/tiktok/{store}/setup/{step}', [TiktokSettingsController::class, 'setupStep'])->whereIn('step', ['shop', 'categories'])->name('ext.tiktok.setup_step');

        // Status-map saves
        Route::post('/tiktok/{store}/order-status-map', [TiktokSettingsController::class, 'saveOrderStatusMap'])->name('ext.tiktok.order_status_map');
        Route::post('/tiktok/{store}/return-status-map', [TiktokSettingsController::class, 'saveReturnStatusMap'])->name('ext.tiktok.return_status_map');

        // Categories — sync from TikTok and persist
        Route::post('/tiktok/{store}/categories/sync', [TikTokProductGroupController::class, 'syncCategories'])->name('ext.tiktok.categories.sync');

        // Product groups — writes
        Route::get('/tiktok/{store}/product-groups/create', [TikTokProductGroupController::class, 'create'])->name('ext.tiktok.product-groups.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/tiktok/{store}/product-groups/fetch-attributes', [TikTokProductGroupController::class, 'fetchAttributesAjax'])->name('ext.tiktok.product-groups.fetchAttributes');
        Route::post('/tiktok/{store}/product-groups', [TikTokProductGroupController::class, 'store'])->name('ext.tiktok.product-groups.store');
        Route::put('/tiktok/{store}/product-groups/{id}', [TikTokProductGroupController::class, 'update'])->name('ext.tiktok.product-groups.update');
        Route::delete('/tiktok/{store}/product-groups/{id}', [TikTokProductGroupController::class, 'destroy'])->name('ext.tiktok.product-groups.destroy');
        // ext.tiktok.product-groups.addProducts (POST ids[] from "Add a
        // product by name") retired with that section on 2026-09-05 (#201).
        // The group page's Add products panel (#201, Draft C): one of this
        // store's products into the group, in place; `move` honours the
        // one-group rule when another group owns it.
        Route::post('/tiktok/{store}/product-groups/{id}/products/{productId}/add', [TikTokProductGroupController::class, 'addProduct'])->whereNumber('id')->whereNumber('productId')->name('ext.tiktok.product-groups.addProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/sync-id', [TikTokProductGroupController::class, 'syncId'])->name('ext.tiktok.product-groups.syncId');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/unlink', [TikTokProductGroupController::class, 'unlinkProduct'])->name('ext.tiktok.product-groups.unlinkProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/link', [TikTokProductGroupController::class, 'linkProduct'])->name('ext.tiktok.product-groups.linkProduct');
        Route::delete('/tiktok/{store}/product-groups/{id}/products/{product}', [TikTokProductGroupController::class, 'removeProduct'])->name('ext.tiktok.product-groups.removeProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/mass-remove', [TikTokProductGroupController::class, 'massRemove'])->name('ext.tiktok.product-groups.massRemove');
        Route::post('/tiktok/{store}/product-groups/{id}/delete-from-tiktok', [TikTokProductGroupController::class, 'deleteFromTikTok'])->name('ext.tiktok.product-groups.deleteFromTikTok');
        Route::post('/tiktok/{store}/product-groups/{id}/products/check', [TikTokProductGroupController::class, 'checkAgainstTikTok'])->whereNumber('id')->name('ext.tiktok.product-groups.check');
        Route::post('/tiktok/{store}/product-groups/{id}/push', [TikTokProductGroupController::class, 'push'])->name('ext.tiktok.product-groups.push');
        Route::post('/tiktok/{store}/product-groups/{id}/update-product', [TikTokProductGroupController::class, 'updateProduct'])->name('ext.tiktok.product-groups.updateProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/push-prices', [TikTokProductGroupController::class, 'pushPrices'])->name('ext.tiktok.product-groups.pushPrices');
        Route::post('/tiktok/{store}/product-groups/{id}/push-stock', [TikTokProductGroupController::class, 'pushStock'])->name('ext.tiktok.product-groups.pushStock');

        // Listing writes (#156)
        Route::post('/tiktok/{store}/products/fetch-attributes', [TikTokProductController::class, 'fetchAttributes'])->name('ext.tiktok.products.fetchAttributes');
        Route::get('/tiktok/{store}/products/search-catalog', [TikTokProductController::class, 'searchCatalogProducts'])->name('ext.tiktok.products.search_catalog');
        // Stateless import (the owner, 2026-09-04): the page's GET carries ?fetch=1
        // to read the shop live; these act on the values the page carries.
        Route::post('/tiktok/{store}/products/import-one', [TikTokProductController::class, 'importOne'])->name('ext.tiktok.products.import_one');
        // The Import page's lazy picture: TikTok's product search carries none, the detail does (2026-09-07).
        Route::get('/tiktok/{store}/products/thumb/{ref}', [TikTokProductController::class, 'thumb'])->name('ext.tiktok.products.thumb');
        Route::post('/tiktok/{store}/products/import-selected', [TikTokProductController::class, 'importSelected'])->name('ext.tiktok.products.import_selected');
        Route::post('/tiktok/{store}/products/link-item', [TikTokProductController::class, 'linkItem'])->name('ext.tiktok.products.link_item');
        // Add products to this store's list (#201): a catalogue product joins
        // the store, unpushed, ready to configure and push from This store.
        Route::post('/tiktok/{store}/products/add-to-store', [TikTokProductController::class, 'addToStoreBulk'])->name('ext.tiktok.products.add_to_store_bulk');
        Route::post('/tiktok/{store}/products/{productId}/add-to-store', [TikTokProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.tiktok.products.add_to_store');
        // Remove from this store's list (#201): the inverse of Add. No
        // blocker; it never touches TikTok Shop.
        Route::post('/tiktok/{store}/products/{productId}/remove-from-store', [TikTokProductController::class, 'removeFromStore'])->whereNumber('productId')->name('ext.tiktok.products.remove_from_store');
        Route::post('/tiktok/{store}/products/check', [TikTokProductController::class, 'checkAgainstTikTok'])->name('ext.tiktok.products.check');
        // The Listings page's bulk verbs (parity, 2026-09-07).
        // Multi-tick push, first in the bulk bar on every channel (2026-09-09).
        Route::post('/tiktok/{store}/products/bulk/push', [TikTokProductController::class, 'bulkPush'])->name('ext.tiktok.products.bulk_push');
        Route::post('/tiktok/{store}/products/bulk/push-stock', [TikTokProductController::class, 'bulkPushStock'])->name('ext.tiktok.products.bulk_push_stock');
        Route::post('/tiktok/{store}/products/bulk/push-price', [TikTokProductController::class, 'bulkPushPrice'])->name('ext.tiktok.products.bulk_push_price');
        Route::post('/tiktok/{store}/products/bulk/delete', [TikTokProductController::class, 'bulkDeleteFromTikTok'])->name('ext.tiktok.products.bulk_delete');
        Route::post('/tiktok/{store}/products/bulk/remove-from-store', [TikTokProductController::class, 'bulkRemoveFromStore'])->name('ext.tiktok.products.bulk_remove_from_store');
        // Activate / deactivate the selection, and refresh the status mirror on demand.
        Route::post('/tiktok/{store}/products/bulk/toggle', [TikTokListingController::class, 'bulkToggle'])->name('ext.tiktok.products.bulk_toggle');
        Route::post('/tiktok/{store}/products/refresh-status', [TikTokProductController::class, 'refreshStatus'])->name('ext.tiktok.products.refresh_status');
        Route::post('/tiktok/{store}/products/{productId}/push', [TikTokProductController::class, 'pushDirect'])->whereNumber('productId')->name('ext.tiktok.products.push_direct');
        Route::post('/tiktok/{store}/products/{productId}/push-stock', [TikTokProductController::class, 'pushStock'])->whereNumber('productId')->name('ext.tiktok.products.push_stock');
        Route::post('/tiktok/{store}/products/{productId}/push-price', [TikTokProductController::class, 'pushPrice'])->whereNumber('productId')->name('ext.tiktok.products.push_price');
        Route::post('/tiktok/{store}/products/{productId}/unlink', [TikTokProductController::class, 'unlink'])->whereNumber('productId')->name('ext.tiktok.products.unlink');
        Route::post('/tiktok/{store}/products/{productId}/delete', [TikTokProductController::class, 'deleteFromTikTok'])->whereNumber('productId')->name('ext.tiktok.products.delete');
        Route::post('/tiktok/{store}/listings/{productId}/push-update', [TikTokListingController::class, 'pushListingUpdate'])->whereNumber('productId')->name('ext.tiktok.listings.push_update');
        Route::post('/tiktok/{store}/listings/{productId}/toggle', [TikTokListingController::class, 'toggleListing'])->whereNumber('productId')->name('ext.tiktok.listings.toggle');

        // Per-product discount rule + its push (#146)
        Route::put('/tiktok/{store}/listings/{productId}', [TikTokListingController::class, 'update'])->whereNumber('productId')->name('ext.tiktok.listings.update');

        // Coupon writes (#153): create, deactivate. Pass-throughs; TikTok owns the object.
        Route::post('/tiktok/{store}/coupons', [TikTokCouponController::class, 'store'])->name('ext.tiktok.coupons.store');
        Route::post('/tiktok/{store}/coupons/{couponId}/deactivate', [TikTokCouponController::class, 'deactivate'])->where('couponId', '[A-Za-z0-9_-]+')->name('ext.tiktok.coupons.deactivate');
    });

    // ── TikTok Orders — READ (browse, show, AWB/tracking download) ──
    Route::group([], function () {
        Route::get('/tiktok/{store}/orders', [TikTokOrderController::class, 'index'])->name('ext.tiktok.orders.index');
        Route::get('/tiktok/{store}/orders/returns', [TikTokOrderController::class, 'returns'])->name('ext.tiktok.orders.returns');
        Route::get('/tiktok/{store}/orders/{id}/show', [TikTokOrderController::class, 'show'])->name('ext.tiktok.orders.show');
        Route::get('/tiktok/{store}/orders/{id}/awb', [TikTokOrderController::class, 'awbPdf'])->name('ext.tiktok.orders.awb');
        Route::get('/tiktok/{store}/orders/{id}/tracking', [TikTokOrderController::class, 'tracking'])->name('ext.tiktok.orders.tracking');
    });

    // ── TikTok Orders — WRITE (sync, ship) ──────────────────────
    Route::group([], function () {
        Route::post('/tiktok/{store}/orders/fetch', [TikTokOrderController::class, 'fetch'])->name('ext.tiktok.orders.fetch');
        // The page-driven fetch (2026-09-07): begin, step, stop, state. Same permission as orders.fetch.
        Route::post('/tiktok/{store}/orders/fetch-runs', [TikTokOrderController::class, 'fetchRunBegin'])->name('ext.tiktok.orders.fetch_run_begin');
        Route::post('/tiktok/{store}/orders/fetch-runs/{run}/step', [TikTokOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_step');
        Route::post('/tiktok/{store}/orders/fetch-runs/{run}/stop', [TikTokOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_stop');
        Route::get('/tiktok/{store}/orders/fetch-runs/{run}', [TikTokOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_state');
        Route::post('/tiktok/{store}/orders/returns/fetch', [TikTokOrderController::class, 'fetchReturns'])->name('ext.tiktok.orders.fetch_returns');
        Route::post('/tiktok/{store}/orders/update-statuses', [TikTokOrderController::class, 'updateStatuses'])->name('ext.tiktok.orders.updateStatuses');
        Route::post('/tiktok/{store}/orders/{id}/ship', [TikTokOrderController::class, 'shipOrder'])->name('ext.tiktok.orders.ship');
    });
});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/tiktok was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key. The route lives here now, so the page derives
// tiktok/dashboard and is enforced at the door.
//
// The digits-only {store} tail keeps this from swallowing
// /channels/tiktok/orders. A store suffix that resolves to no card is a 404.
Route::middleware(['auth', \Extensions\tiktok\Http\ResolveTikTokStore::class])->prefix('channels')->group(function () {
    Route::get('/tiktok/{store?}', [\Extensions\tiktok\Controllers\TiktokDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.tiktok.dashboard');
});

// ── LEGACY STORELESS URLS ───────────────────────────────────────────────
//
// Multi-store (the owner 2026-09-05): every TikTok page now lives under
// /channels/tiktok/{store}/… . Bookmarks from the single-store era arrive
// without the segment; a GET is redirected to the same page on the default
// store, query string intact. Registered after the dashboard so
// /channels/tiktok/{digits} keeps meaning a dashboard.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/tiktok/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\tiktok\Models\TikTokSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/tiktok/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.tiktok.legacy_storeless');
});
