<?php

use Extensions\shopee\Controllers\ShopeeSettingsController;
use Extensions\shopee\Controllers\ShopeeApiExplorerController;
use Extensions\shopee\Controllers\ShopeeOrderController;
use Extensions\shopee\Controllers\ShopeeCategoryController;
use Extensions\shopee\Controllers\ShopeeCategoryAttributeController;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Controllers\ShopeeProductController;
use Extensions\shopee\Controllers\ShopeeLogisticsController;
use Illuminate\Support\Facades\Route;

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes (display-only, no DB write, no marketplace API write):
//   gated by 'view_shopee|manage_shopee' — granting EITHER tier suffices.
//   The pipe-OR syntax is honored by App\Http\Middleware\EnsurePermission.
//
// Write routes (any mutation: DB write, OAuth, sync push, marketplace state
// change like ship/cancel/delete, status-map save):
//   gated by 'manage_shopee' (or 'manage_shopee_orders' inside the orders
//   block) — read-only operators MUST NOT trigger these.
//
// Note: Shopee's OAuth callback is handled via the bare `/` route by the
// ShopeeExtension::handleRootRoute() RootRouteHandler hook (Shopee requires
// a domain-level redirect URI). That dispatch is outside this auth-gated
// block by design.
//
// ── OAUTH SURFACES: EXEMPT FROM THE /integrations PREFIX ────────────────
//
// IA migration (2026-08-08, Task 2). Every other page in this file moved
// under /integrations/shopee/… so the address bar names the seller center
// the operator is standing in. These two did not, and must not:
//
//   /shopee            ext.shopee.index      ShopeeController::
//                                            computedRedirectUri() returns
//                                            route('ext.shopee.index'), and
//                                            that exact string is registered
//                                            as the redirect URI in the
//                                            Shopee Open Platform console.
//                                            Shopee redirects the merchant's
//                                            browser here after consent.
//                                            Moving it breaks live OAuth for
//                                            every install until each seller
//                                            re-registers by hand.
//   /shopee/authorize  ext.shopee.authorize  the consent-flow entry point,
//                                            exempted with its callback per
//                                            the migration ledger's
//                                            pre-dispatch constraint.
//
// A 301 does not rescue these: the URL is a value compared byte-for-byte at
// the marketplace, not a link a browser follows. They keep their URIs
// permanently, which is the documented exception to the workspace-context
// rule, not a step still to be done.
Route::middleware(['auth'])->group(function () {
    Route::get('/shopee', [ShopeeSettingsController::class, 'index'])->name('ext.shopee.index');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/shopee/authorize', [ShopeeSettingsController::class, 'redirectToShopeeAuth'])->name('ext.shopee.authorize')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
});

// Everything below moves under /integrations. Route NAMES are unchanged; the
// `/shopee/…` literals inside the group are now the channel segment beneath
// that prefix, so `/shopee/orders` reads as `/integrations/shopee/orders`.
Route::middleware(['auth', \Extensions\shopee\Http\ResolveShopeeStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    // ── Shopee Settings + Catalog management ────────────────────
    Route::group([], function () {
        // Catalog browsing (lists, search)
        // Creating a store is the one settings write with no store to stand
        // in yet (S4b, #194): the card's Add store modal posts a name here.
        Route::post('/shopee/stores', [ShopeeSettingsController::class, 'createStore'])->name('ext.shopee.stores.store');

        // The per-store settings page (multi-store S4, #194); the storeless
        // /shopee OAuth landing redirects here.
        Route::get('/shopee/{store}/settings', [ShopeeSettingsController::class, 'showSettings'])->name('ext.shopee.settings.show');
        Route::get('/shopee/{store}/categories', [ShopeeCategoryController::class, 'index'])->name('ext.shopee.categories.index');
        Route::get('/shopee/{store}/categories/{id}/attributes', [ShopeeCategoryAttributeController::class, 'show'])->name('ext.shopee.categories.attributes.show');
        Route::get('/shopee/{store}/logistics', [ShopeeLogisticsController::class, 'index'])->name('ext.shopee.logistics.index');

        // Product Groups — read-only list + search + edit-form RENDER
        Route::get('/shopee/{store}/product-groups', [ShopeeProductGroupController::class, 'index'])->name('ext.shopee.product-groups.index');
        // ext.shopee.product-groups.searchProducts (the whole-catalogue picker
        // behind the dual list and "Add a product by name") retired with both
        // on 2026-09-05 (#201): a group only holds what THIS STORE carries,
        // and the Add products panel below searches exactly that.
        Route::get('/shopee/{store}/product-groups/brands', [ShopeeProductGroupController::class, 'brandsForCategory'])->name('ext.shopee.product-groups.brandsForCategory');
        Route::get('/shopee/{store}/product-groups/categories/search', [ShopeeProductGroupController::class, 'searchCategories'])->name('ext.shopee.product-groups.searchCategories');
        Route::get('/shopee/{store}/product-groups/categories/lookup', [ShopeeProductGroupController::class, 'categoryLookup'])->name('ext.shopee.product-groups.categoryLookup');
        Route::get('/shopee/{store}/product-groups/{id}/edit', [ShopeeProductGroupController::class, 'edit'])->name('ext.shopee.product-groups.edit');
        Route::get('/shopee/{store}/product-groups/{id}/products', [ShopeeProductGroupController::class, 'products'])->name('ext.shopee.product-groups.products');
        // The group page's Add products panel search (#201, Draft C): this
        // store's products, flagged in-group or owned elsewhere. A read.
        Route::get('/shopee/{store}/product-groups/{id}/products/search', [ShopeeProductGroupController::class, 'productSearch'])->whereNumber('id')->name('ext.shopee.product-groups.productSearch');
        // Read tier, beside the products list it mirrors, NOT with the
        // writes. It asks Shopee what it holds and prints the answer; it
        // adopts nothing and deletes nothing. Noticing that the two sides
        // have drifted is exactly what a view-only operator is for.
        Route::get('/shopee/{store}/product-groups/{id}/orphans', [ShopeeProductGroupController::class, 'orphans'])->name('ext.shopee.product-groups.orphans');

        // Products — list, search-catalog, push-form RENDER
        Route::get('/shopee/{store}/products', [ShopeeProductController::class, 'index'])->name('ext.shopee.products.index');
        Route::get('/shopee/{store}/products/search-catalog', [ShopeeProductController::class, 'searchCatalogProducts'])->name('ext.shopee.products.search_catalog');
        // The Add products panel's catalogue search (#201, Draft C): a read
        // that lives beside the adds it feeds, in the read tier.
        Route::get('/shopee/{store}/products/catalogue-search', [ShopeeProductController::class, 'catalogueSearch'])->name('ext.shopee.products.catalogue_search');
        Route::get('/shopee/{store}/products/categories/search', [ShopeeProductController::class, 'searchCategories'])->name('ext.shopee.products.searchCategories');
        Route::get('/shopee/{store}/products/categories/lookup', [ShopeeProductController::class, 'categoryLookup'])->name('ext.shopee.products.categoryLookup');
        Route::get('/shopee/{store}/products/brands', [ShopeeProductController::class, 'brandsForCategory'])->name('ext.shopee.products.brandsForCategory');
        Route::get('/shopee/{store}/products/import', [ShopeeProductController::class, 'importPage'])->name('ext.shopee.products.import');
        Route::get('/shopee/{store}/products/{productId}/push-review', [ShopeeProductController::class, 'pushReview'])->whereNumber('productId')->name('ext.shopee.products.push_review');
        // The combined review form for a multi-select push (#151). Before
        // the {productId} route in the file for the reader; the router
        // never confuses them because bulk is not a number.

        // Vouchers (#153): a live read of the shop's vouchers.
        Route::get('/shopee/{store}/vouchers', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'index'])->name('ext.shopee.vouchers.index');

        // The individual listing page: live marketplace state + the
        // listing record's own settings and content. Readable by the view
        // tier (the page renders read-only with every field disabled and
        // no verbs, critique 2026-08-25); every write it offers is a
        // separate manage route.
        Route::get('/shopee/{store}/listings/{productId}', [ShopeeProductController::class, 'listing'])
            ->whereNumber('productId')->name('ext.shopee.listings.edit');
    });

    // ── Shopee Settings + Catalog WRITES ────────────────────────
    Route::group([], function () {
        // OAuth / token / mode. ext.shopee.authorize is NOT here: it is one of
        // the two exempt OAuth surfaces registered above, outside this prefix.
        Route::post('/shopee/{store}/save', [ShopeeSettingsController::class, 'save'])->name('ext.shopee.save');
        // Setting up the store (2026-09-05): one step per read a push needs
        // (categories, couriers), answering a plain sentence as JSON for the
        // progress popup on Settings > Connection.
        Route::post('/shopee/{store}/setup/{step}', [ShopeeSettingsController::class, 'setupStep'])->whereIn('step', ['categories', 'couriers'])->name('ext.shopee.setup_step');
        // Hard delete of a store (the owner, 2026-09-05): the typed name confirms;
        // everything the store owns goes, core orders stay under its name.
        Route::post('/shopee/{store}/delete', [ShopeeSettingsController::class, 'destroyStore'])->name('ext.shopee.stores.destroy');
        Route::post('/shopee/{store}/toggle-mode', [ShopeeSettingsController::class, 'toggleMode'])->name('ext.shopee.toggle_mode');
        Route::post('/shopee/{store}/auth-url', [ShopeeSettingsController::class, 'buildAuthUrl'])->name('ext.shopee.auth_url');
        Route::post('/shopee/{store}/token/get', [ShopeeSettingsController::class, 'tokenGet'])->name('ext.shopee.token_get');
        Route::post('/shopee/{store}/token/refresh', [ShopeeSettingsController::class, 'tokenRefresh'])->name('ext.shopee.token_refresh');

        // API Explorer — calls live Shopee API; treat as privileged write.
        Route::post('/shopee/{store}/call', [ShopeeApiExplorerController::class, 'callApi'])->name('ext.shopee.call_api');
        Route::post('/shopee/{store}/explorer/run', [ShopeeApiExplorerController::class, 'explorerRun'])->name('ext.shopee.explorer_run');
        Route::post('/shopee/{store}/packs/run', [ShopeeApiExplorerController::class, 'packsRun'])->name('ext.shopee.packs_run');
        // ext.shopee.logistics_channels (the Settings-page "Fetch couriers")
        // retired 2026-09-05: it only flashed Shopee's raw answer; Catalog >
        // Logistics (ext.shopee.logistics.fetch) reads and caches the list.

        // Status-map saves + sync window saves
        Route::post('/shopee/{store}/order-status-map', [ShopeeSettingsController::class, 'saveOrderStatusMap'])->name('ext.shopee.order_status_map');
        Route::post('/shopee/{store}/return-status-map', [ShopeeSettingsController::class, 'saveReturnStatusMap'])->name('ext.shopee.return_status_map');

        // API logging admin
        Route::post('/shopee/{store}/api-log-mode', [ShopeeSettingsController::class, 'setApiLogMode'])->name('ext.shopee.api_log_mode');
        Route::delete('/shopee/{store}/api-logs', [ShopeeSettingsController::class, 'clearApiLogs'])->name('ext.shopee.clear_api_logs');
        Route::post('/shopee/{store}/purge-raw', [ShopeeSettingsController::class, 'purgeRaw'])->name('ext.shopee.purge_raw');

        // Shopee Categories — fetches that pull from Shopee and persist locally
        Route::post('/shopee/{store}/categories/fetch', [ShopeeCategoryController::class, 'fetch'])->name('ext.shopee.categories.fetch');
        Route::post('/shopee/{store}/categories/{id}/attributes/fetch', [ShopeeCategoryAttributeController::class, 'fetch'])->name('ext.shopee.categories.attributes.fetch');

        // Shopee Logistics
        Route::post('/shopee/{store}/logistics/fetch', [ShopeeLogisticsController::class, 'fetch'])->name('ext.shopee.logistics.fetch');

        // Shopee Product Groups — writes
        Route::get('/shopee/{store}/product-groups/create', [ShopeeProductGroupController::class, 'create'])->name('ext.shopee.product-groups.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::post('/shopee/{store}/product-groups', [ShopeeProductGroupController::class, 'store'])->name('ext.shopee.product-groups.store');
        Route::put('/shopee/{store}/product-groups/{id}', [ShopeeProductGroupController::class, 'update'])->name('ext.shopee.product-groups.update');
        Route::delete('/shopee/{store}/product-groups/{id}', [ShopeeProductGroupController::class, 'destroy'])->name('ext.shopee.product-groups.destroy');
        // ext.shopee.product-groups.addProducts (POST ids[] from "Add a
        // product by name") retired with that section on 2026-09-05 (#201).
        // The group page's Add products panel (#201, Draft C): one of this
        // store's products into the group, in place; `move` honours the
        // one-group rule when another group owns it.
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/add', [ShopeeProductGroupController::class, 'addProduct'])->whereNumber('id')->whereNumber('productId')->name('ext.shopee.product-groups.addProduct');
        // Send reconciles against Shopee and then creates or updates
        // accordingly. push and updateProduct stay because the bulk bar and
        // the scheduler still address them directly; Send is what the row
        // offers, so no state of the page can hide the action that works.
        Route::post('/shopee/{store}/product-groups/{id}/products/send', [ShopeeProductGroupController::class, 'send'])->name('ext.shopee.product-groups.send');
        Route::post('/shopee/{store}/product-groups/{id}/products/check', [ShopeeProductGroupController::class, 'checkAgainstShopee'])->name('ext.shopee.product-groups.check');
        Route::post('/shopee/{store}/product-groups/{id}/products/push', [ShopeeProductGroupController::class, 'push'])->name('ext.shopee.product-groups.push');
        Route::post('/shopee/{store}/product-groups/{id}/products/update-product', [ShopeeProductGroupController::class, 'updateProduct'])->name('ext.shopee.product-groups.updateProduct');
        Route::post('/shopee/{store}/product-groups/{id}/products/push-prices', [ShopeeProductGroupController::class, 'pushPrices'])->name('ext.shopee.product-groups.pushPrices');
        Route::post('/shopee/{store}/product-groups/{id}/products/push-stock', [ShopeeProductGroupController::class, 'pushStock'])->name('ext.shopee.product-groups.pushStock');
        Route::post('/shopee/{store}/product-groups/{id}/products/mass-remove', [ShopeeProductGroupController::class, 'massRemove'])->name('ext.shopee.product-groups.massRemove');
        Route::post('/shopee/{store}/product-groups/{id}/products/delete-from-shopee', [ShopeeProductGroupController::class, 'deleteFromShopee'])->name('ext.shopee.product-groups.deleteFromShopee');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/sync-id', [ShopeeProductGroupController::class, 'syncId'])->name('ext.shopee.product-groups.syncId');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/unlink', [ShopeeProductGroupController::class, 'unlinkProduct'])->name('ext.shopee.product-groups.unlinkProduct');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/link', [ShopeeProductGroupController::class, 'linkProduct'])->name('ext.shopee.product-groups.linkProduct');
        Route::delete('/shopee/{store}/product-groups/{id}/products/{productId}', [ShopeeProductGroupController::class, 'removeProduct'])->name('ext.shopee.product-groups.removeProduct');
        Route::post('/shopee/{store}/product-groups/{id}/template/sync', [ShopeeProductGroupController::class, 'syncTemplate'])->name('ext.shopee.product-groups.template_sync');
        Route::post('/shopee/{store}/product-groups/refresh-categories', [ShopeeProductGroupController::class, 'refreshCategories'])->name('ext.shopee.product-groups.refreshCategories');
        Route::post('/shopee/{store}/product-groups/fetch-attributes', [ShopeeProductGroupController::class, 'fetchAttributesAjax'])->name('ext.shopee.product-groups.fetchAttributes');

        // Shopee Products — writes
        // ext.shopee.products.add died with #196's review: it was a picker
        // in front of the push form, and the catalogue-first table already
        // offers every unlisted row its own push. The owner: "I dont think this
        // feature should exist... not unless you give me a reason." No
        // reason survived - it created nothing (core or Shopee), it only
        // navigated, and a second door to the same room is how doors rot.
        Route::post('/shopee/{store}/products/check', [ShopeeProductController::class, 'checkAgainstShopee'])->name('ext.shopee.products.check');
        Route::post('/shopee/{store}/products/bulk/sync/quantity', [ShopeeProductController::class, 'bulkSyncQuantity'])->name('ext.shopee.products.bulk_sync_quantity');
        // Unlist / relist the selection in one call (the owner, 2026-08-28).
        Route::post('/shopee/{store}/products/bulk/toggle', [ShopeeProductController::class, 'bulkToggle'])->name('ext.shopee.products.bulk_toggle');
        // Refresh the listing status mirror on demand (the automation does the same every 30 minutes).
        Route::post('/shopee/{store}/products/refresh-status', [ShopeeProductController::class, 'refreshStatus'])->name('ext.shopee.products.refresh_status');
        Route::post('/shopee/{store}/products/bulk/sync/price', [ShopeeProductController::class, 'bulkSyncPrice'])->name('ext.shopee.products.bulk_sync_price');
        Route::post('/shopee/{store}/products/bulk/delete', [ShopeeProductController::class, 'bulkDeleteFromShopee'])->name('ext.shopee.products.bulk_delete');
        Route::post('/shopee/{store}/products/bulk/remove-from-store', [ShopeeProductController::class, 'bulkRemoveFromStore'])->name('ext.shopee.products.bulk_remove_from_store');
        // Stateless import (the owner, 2026-09-04): the page's GET carries
        // ?fetch=1 to read the shop live; these act on the values the
        // rendered page carries. Nothing is saved - nothing to dismiss.
        Route::post('/shopee/{store}/products/import-one', [ShopeeProductController::class, 'importOne'])->name('ext.shopee.products.import_one');
        Route::post('/shopee/{store}/products/import-selected', [ShopeeProductController::class, 'importSelected'])->name('ext.shopee.products.import_selected');
        Route::post('/shopee/{store}/products/link-item', [ShopeeProductController::class, 'linkItem'])->name('ext.shopee.products.link_item');
        // Add products to this store's list (#201): a catalogue product joins
        // the store, unpushed, ready to configure and push from This store.
        Route::post('/shopee/{store}/products/add-to-store', [ShopeeProductController::class, 'addToStoreBulk'])->name('ext.shopee.products.add_to_store_bulk');
        Route::post('/shopee/{store}/products/{productId}/add-to-store', [ShopeeProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.shopee.products.add_to_store');
        // Remove from this store's list (#201): the inverse of Add. No
        // blocker; it never touches Shopee.
        Route::post('/shopee/{store}/products/{productId}/remove-from-store', [ShopeeProductController::class, 'removeFromStore'])->whereNumber('productId')->name('ext.shopee.products.remove_from_store');
        Route::post('/shopee/{store}/products/rebuild-cache', [ShopeeProductController::class, 'rebuildCache'])->name('ext.shopee.products.rebuild_cache');
        Route::post('/shopee/{store}/products/{productId}/push-direct', [ShopeeProductController::class, 'pushDirect'])->name('ext.shopee.products.push_direct');
        Route::post('/shopee/{store}/products/{productId}/sync/shopee-id', [ShopeeProductController::class, 'syncSingleProductId'])->name('ext.shopee.products.sync_shopee_id');
        Route::post('/shopee/{store}/products/{productId}/sync/quantity', [ShopeeProductController::class, 'syncQuantity'])->name('ext.shopee.products.sync_quantity');
        Route::post('/shopee/{store}/products/{productId}/sync/price', [ShopeeProductController::class, 'syncPrice'])->name('ext.shopee.products.sync_price');
        Route::post('/shopee/{store}/listings/{productId}', [ShopeeProductController::class, 'saveListing'])->whereNumber('productId')->name('ext.shopee.listings.update');
        Route::post('/shopee/{store}/listings/{productId}/toggle', [ShopeeProductController::class, 'toggleListing'])->whereNumber('productId')->name('ext.shopee.listings.toggle');
        // Re-read the item's variations from Shopee and rewire the link rows (Phase E, #184).
        Route::post('/shopee/{store}/listings/{productId}/reread-variations', [ShopeeProductController::class, 'rereadVariations'])->whereNumber('productId')->name('ext.shopee.listings.reread_variations');
        // Add the catalogue's missing variations to the live listing (Phase F, #184).
        Route::post('/shopee/{store}/listings/{productId}/push-missing-variations', [ShopeeProductController::class, 'pushMissingVariations'])->whereNumber('productId')->name('ext.shopee.listings.push_missing_variations');
        Route::post('/shopee/{store}/listings/{productId}/push-update', [ShopeeProductController::class, 'pushListingUpdate'])->whereNumber('productId')->name('ext.shopee.listings.push_update');
        Route::post('/shopee/{store}/products/fetch-attributes', [ShopeeProductController::class, 'fetchAttributes'])->name('ext.shopee.products.fetchAttributes');
        Route::post('/shopee/{store}/listings/{productId}/push-discount', [ShopeeProductController::class, 'pushListingDiscount'])->whereNumber('productId')->name('ext.shopee.listings.push_discount');

        // Voucher writes (#153): create, end an ongoing one, delete an
        // upcoming one. All pass-throughs; Shopee owns the object.
        Route::post('/shopee/{store}/vouchers', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'store'])->name('ext.shopee.vouchers.store');
        Route::post('/shopee/{store}/vouchers/{voucherId}/end', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'end'])->whereNumber('voucherId')->name('ext.shopee.vouchers.end');
        Route::post('/shopee/{store}/vouchers/{voucherId}/delete', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'destroy'])->whereNumber('voucherId')->name('ext.shopee.vouchers.delete');
        Route::post('/shopee/{store}/products/{productId}/unlink', [ShopeeProductController::class, 'unlink'])->name('ext.shopee.products.unlink');
        Route::post('/shopee/{store}/products/{productId}/delete', [ShopeeProductController::class, 'deleteFromShopee'])->name('ext.shopee.products.delete');
    });

    // ── Shopee Orders — READ (browse, view show pages, download docs) ──
    // Read-only orders viewer can pull tracking / AWB / shipping address
    // queries because those are server-rendered from already-fetched data.
    Route::group([], function () {
        Route::get('/shopee/{store}/orders', [ShopeeOrderController::class, 'index'])->name('ext.shopee.orders.index');
        Route::get('/shopee/{store}/orders/returns', [ShopeeOrderController::class, 'returns'])->name('ext.shopee.orders.returns');
        Route::get('/shopee/{store}/orders/returns/{returnSn}', [ShopeeOrderController::class, 'returnDetail'])->where('returnSn', '[A-Za-z0-9]+')->name('ext.shopee.orders.return_detail');
        // A READ, deliberately in the read tier: it asks Shopee what may be
        // offered on this return and pushes nothing. It is the call that lets
        // the interface show only resolutions Shopee will accept, instead of
        // guessing and failing in front of a customer.
        Route::get('/shopee/{store}/orders/returns/{returnSn}/solutions', [ShopeeOrderController::class, 'returnSolutions'])
            ->where('returnSn', '[A-Za-z0-9]+')
            ->name('ext.shopee.orders.return_solutions');
        Route::get('/shopee/{store}/orders/{orderSn}', [ShopeeOrderController::class, 'show'])->name('ext.shopee.orders.show');
        Route::get('/shopee/{store}/orders/{orderSn}/tracking', [ShopeeOrderController::class, 'getTrackingNumber'])->name('ext.shopee.orders.tracking');
        Route::get('/shopee/{store}/orders/{orderSn}/shipping-addresses', [ShopeeOrderController::class, 'getShippingAddresses'])->name('ext.shopee.orders.shipping_addresses');
        Route::get('/shopee/{store}/orders/{orderSn}/tracking-info', [ShopeeOrderController::class, 'getTrackingInfo'])->name('ext.shopee.orders.tracking_info');
        Route::get('/shopee/{store}/orders/{orderSn}/awb', [ShopeeOrderController::class, 'awbPdf'])->name('ext.shopee.orders.awb');
    });

    // ── Shopee Orders — WRITE (sync, ship, reset) ──────────────
    Route::group([], function () {
        Route::post('/shopee/{store}/orders/fetch', [ShopeeOrderController::class, 'fetch'])->name('ext.shopee.orders.fetch');
        // The page-driven fetch (2026-09-07): begin, step, stop, state. Same permission as orders.fetch.
        Route::post('/shopee/{store}/orders/fetch-runs', [ShopeeOrderController::class, 'fetchRunBegin'])->name('ext.shopee.orders.fetch_run_begin');
        Route::post('/shopee/{store}/orders/fetch-runs/{run}/step', [ShopeeOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_step');
        Route::post('/shopee/{store}/orders/fetch-runs/{run}/stop', [ShopeeOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_stop');
        Route::get('/shopee/{store}/orders/fetch-runs/{run}', [ShopeeOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_state');
        Route::post('/shopee/{store}/orders/update-statuses', [ShopeeOrderController::class, 'updateStatuses'])->name('ext.shopee.orders.update_statuses');
        Route::post('/shopee/{store}/orders/reset', [ShopeeOrderController::class, 'reset'])->name('ext.shopee.orders.reset');
        Route::post('/shopee/{store}/orders/{orderSn}/ship', [ShopeeOrderController::class, 'shipOrder'])->name('ext.shopee.orders.ship');
        Route::post('/shopee/{store}/orders/fetch-returns', [ShopeeOrderController::class, 'fetchReturns'])->name('ext.shopee.orders.fetch_returns');
    });

});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/shopee was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Shopee operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// shopee/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/shopee/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth', \Extensions\shopee\Http\ResolveShopeeStore::class])->prefix('channels')->group(function () {
    Route::get('/shopee/{store?}', [\Extensions\shopee\Controllers\ShopeeDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.shopee.dashboard');
});

// ── LEGACY STORELESS URLS ───────────────────────────────────────────────
//
// Multi-store S4 (#194): every Shopee page now lives under
// /channels/shopee/{store}/… . Bookmarks and muscle memory from the
// single-store era arrive without the segment; a GET is redirected to the
// same page on the default store, query string intact. Registered after
// the dashboard so /channels/shopee/{digits} keeps meaning a dashboard.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/shopee/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\shopee\Models\ShopeeSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/shopee/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.shopee.legacy_storeless');
});
