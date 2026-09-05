<?php

use Extensions\lazada\Controllers\LazadaSettingsController;
use Extensions\lazada\Controllers\LazadaApiExplorerController;
use Extensions\lazada\Controllers\LazadaProductController;
use Extensions\lazada\Controllers\LazadaCategoryController;
use Extensions\lazada\Controllers\LazadaCategoryAttributeController;
use Extensions\lazada\Controllers\LazadaOrderController;
use Extensions\lazada\Controllers\LazadaProductGroupController;
use Extensions\lazada\Controllers\LazadaBrandController;
use Illuminate\Support\Facades\Route;

// ── OAUTH SURFACES: STORELESS, EXEMPT FROM THE {store} SEGMENT ───────────
//
// Multi-store (the owner 2026-09-05): every Lazada page below now lives under
// /channels/lazada/{store}/… . These two do NOT gain the segment.
//
// The callback URL is one fixed URL for the whole install - it is registered
// byte-for-byte in the Lazada Open Platform console and compared on every
// consent round trip, so a {store} in its path would break OAuth for every
// seller. The store the grant belongs to therefore rides the OAuth `state`
// parameter, which Lazada echoes back verbatim; callback() reads the store id
// out of it (see LazadaController::callback). authorize is exempted with it,
// and learns its store from a ?store= query the settings page passes.
//
// Kept outside the auth group so the code isn't lost behind a login redirect.
Route::get('/lazada/callback', [LazadaSettingsController::class, 'callback'])->name('lazada.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/lazada/authorize', [LazadaSettingsController::class, 'redirectToAuth'])->name('ext.lazada.authorize')
        // A form/action page is a MANAGE surface even though it is a GET.
        ->defaults('permission_tier', 'manage');
});

// ── Tier-aware permission map (Phase 3 of view/manage split) ────────────
//
// Read routes (display-only, no DB write, no marketplace API write):
//   gated by 'view_lazada|manage_lazada' — granting EITHER tier suffices.
// Write routes (any mutation): gated by 'manage_lazada'. A few POSTs are pure
// read computations (payload-preview, payload/build, bulk-awb) and carry
// ->defaults('permission_tier', 'view') to stay in the read tier.
//
// Multi-store (the owner 2026-09-05): every page moves under
// /channels/lazada/{store}/… . ResolveLazadaStore binds the store the segment
// names, so LazadaSetting::defaultStore() and every route() call on the page
// answer with it - controllers keep reading defaultStore(), and views need no
// edit. Route NAMES are unchanged. The {store} tail is digits-only.
Route::middleware(['auth', \Extensions\lazada\Http\ResolveLazadaStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    // ── Lazada Settings + Catalog management ────────────────────
    Route::group([], function () {
        // Creating a store is the one settings write with no store to stand
        // in yet: the Integrations board's Add store modal posts a name here.
        Route::post('/lazada/stores', [LazadaSettingsController::class, 'createStore'])->name('ext.lazada.stores.store');

        // The per-store settings page (renders the screen, no writes).
        Route::get('/lazada/{store}/settings', [LazadaSettingsController::class, 'index'])->name('ext.lazada.index');

        // Catalog browsing (lists, search)
        Route::get('/lazada/{store}/products', [LazadaProductController::class, 'index'])->name('ext.lazada.products.index');
        Route::get('/lazada/{store}/products/import', [LazadaProductController::class, 'importPage'])->name('ext.lazada.products.import');

        // Vouchers (#153): a live read of the shop's vouchers.
        Route::get('/lazada/{store}/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'index'])->name('ext.lazada.vouchers.index');
        Route::get('/lazada/{store}/products/search-catalog', [LazadaProductController::class, 'searchCatalogProducts'])->name('ext.lazada.products.search_catalog');
        Route::get('/lazada/{store}/brands', [LazadaBrandController::class, 'index'])->name('ext.lazada.brands.index');
        Route::get('/lazada/{store}/brands/autocomplete', [LazadaBrandController::class, 'autocomplete'])->name('ext.lazada.brands.autocomplete');
        Route::get('/lazada/{store}/categories', [LazadaCategoryController::class, 'index'])->name('ext.lazada.categories.index');
        Route::get('/lazada/{store}/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.show');

        // Product Groups — read-only list + edit-form RENDER
        Route::get('/lazada/{store}/product-groups', [LazadaProductGroupController::class, 'index'])->name('ext.lazada.product-groups.index');
        Route::get('/lazada/{store}/product-groups/search-products', [LazadaProductGroupController::class, 'searchProducts'])->name('ext.lazada.product-groups.searchProducts');
        Route::get('/lazada/{store}/product-groups/{id}/edit', [LazadaProductGroupController::class, 'edit'])->whereNumber('id')->name('ext.lazada.product-groups.edit');
        Route::get('/lazada/{store}/product-groups/{id}/products', [LazadaProductGroupController::class, 'products'])->whereNumber('id')->name('ext.lazada.product-groups.products');
        // Read tier: asks Lazada what it holds and prints the answer, adopting nothing.
        Route::get('/lazada/{store}/product-groups/{id}/orphans', [LazadaProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.lazada.product-groups.orphans');

        // Payload preview / build — POST but pure compute (no persistence, no
        // marketplace call). Read-only.
        Route::post('/lazada/{store}/product/payload-preview', [LazadaApiExplorerController::class, 'productPayloadPreview'])
            ->name('ext.lazada.product_payload_preview')
            ->defaults('permission_tier', 'view');
        Route::post('/lazada/{store}/products/{id}/payload/build', [LazadaProductController::class, 'buildPayload'])->whereNumber('id')->name('ext.lazada.products.payload_build');
    });

    // ── Lazada Settings + Catalog WRITES ────────────────────────
    Route::group([], function () {
        // OAuth / token / mode. ext.lazada.authorize is NOT here: it is one of
        // the two exempt OAuth surfaces registered above, outside this prefix.
        Route::post('/lazada/{store}/save', [LazadaSettingsController::class, 'save'])->name('ext.lazada.save');
        Route::post('/lazada/{store}/toggle-mode', [LazadaSettingsController::class, 'toggleMode'])->name('ext.lazada.toggle_mode');
        Route::post('/lazada/{store}/token/create', [LazadaSettingsController::class, 'tokenCreate'])->name('ext.lazada.token_create');
        Route::post('/lazada/{store}/token/refresh', [LazadaSettingsController::class, 'tokenRefresh'])->name('ext.lazada.token_refresh');
        Route::post('/lazada/{store}/sandbox-token/save', [LazadaSettingsController::class, 'saveSandboxToken'])->name('ext.lazada.sandbox_token_save');

        // Status-map saves
        Route::post('/lazada/{store}/order-status-map', [LazadaSettingsController::class, 'saveOrderStatusMap'])->name('ext.lazada.order_status_map');
        Route::post('/lazada/{store}/reverse-status-map', [LazadaSettingsController::class, 'saveReverseStatusMap'])->name('ext.lazada.reverse_status_map');

        // API logging admin
        Route::post('/lazada/{store}/api-log-mode', [LazadaSettingsController::class, 'setApiLogMode'])->name('ext.lazada.api_log_mode');
        Route::delete('/lazada/{store}/api-logs', [LazadaSettingsController::class, 'clearApiLogs'])->name('ext.lazada.clear_api_logs');
        Route::post('/lazada/{store}/purge-raw', [LazadaSettingsController::class, 'purgeRaw'])->name('ext.lazada.purge_raw');

        // API Explorer — these call the live Lazada API. Privileged write tool.
        Route::post('/lazada/{store}/call', [LazadaApiExplorerController::class, 'callApi'])->name('ext.lazada.call_api');
        Route::post('/lazada/{store}/explorer/run', [LazadaApiExplorerController::class, 'explorerRun'])->name('ext.lazada.explorer_run');
        Route::post('/lazada/{store}/packs/run', [LazadaApiExplorerController::class, 'packsRun'])->name('ext.lazada.packs_run');

        // Lazada Catalog POC — fetch endpoints (call the marketplace API and
        // typically persist results). Write tier.
        Route::post('/lazada/{store}/category/tree', [LazadaApiExplorerController::class, 'categoryTree'])->name('ext.lazada.category_tree');
        Route::post('/lazada/{store}/category/attributes', [LazadaApiExplorerController::class, 'categoryAttributes'])->name('ext.lazada.category_attributes');
        Route::post('/lazada/{store}/category/brands', [LazadaApiExplorerController::class, 'brandsQuery'])->name('ext.lazada.brands_query');
        Route::post('/lazada/{store}/products/get', [LazadaApiExplorerController::class, 'productsGet'])->name('ext.lazada.products_get');
        Route::post('/lazada/{store}/product/create', [LazadaApiExplorerController::class, 'productCreate'])->name('ext.lazada.product_create');
        Route::post('/lazada/{store}/orders/get', [LazadaApiExplorerController::class, 'ordersGet'])->name('ext.lazada.orders_get');
        Route::post('/lazada/{store}/order/items/get', [LazadaApiExplorerController::class, 'orderItemsGet'])->name('ext.lazada.order_items_get');
        Route::post('/lazada/{store}/order/awb/pdf', [LazadaApiExplorerController::class, 'awbPdfGet'])->name('ext.lazada.awb_pdf_get');

        // Lazada Product Groups — writes
        Route::get('/lazada/{store}/product-groups/create', [LazadaProductGroupController::class, 'create'])->name('ext.lazada.product-groups.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/lazada/{store}/product-groups', [LazadaProductGroupController::class, 'store'])->name('ext.lazada.product-groups.store');
        Route::put('/lazada/{store}/product-groups/{id}', [LazadaProductGroupController::class, 'update'])->whereNumber('id')->name('ext.lazada.product-groups.update');
        Route::delete('/lazada/{store}/product-groups/{id}', [LazadaProductGroupController::class, 'destroy'])->whereNumber('id')->name('ext.lazada.product-groups.destroy');
        Route::post('/lazada/{store}/product-groups/{id}/products/add', [LazadaProductGroupController::class, 'addProducts'])->whereNumber('id')->name('ext.lazada.product-groups.addProducts');
        Route::post('/lazada/{store}/product-groups/{id}/products/check', [LazadaProductGroupController::class, 'checkAgainstLazada'])->whereNumber('id')->name('ext.lazada.product-groups.check');
        Route::post('/lazada/{store}/product-groups/{id}/products/push', [LazadaProductGroupController::class, 'push'])->whereNumber('id')->name('ext.lazada.product-groups.push');
        Route::post('/lazada/{store}/product-groups/{id}/products/update-product', [LazadaProductGroupController::class, 'updateProduct'])->whereNumber('id')->name('ext.lazada.product-groups.updateProduct');
        Route::post('/lazada/{store}/product-groups/{id}/products/push-prices', [LazadaProductGroupController::class, 'pushPrices'])->whereNumber('id')->name('ext.lazada.product-groups.pushPrices');
        Route::post('/lazada/{store}/product-groups/{id}/products/push-stock', [LazadaProductGroupController::class, 'pushStock'])->whereNumber('id')->name('ext.lazada.product-groups.pushStock');
        Route::post('/lazada/{store}/product-groups/{id}/products/mass-remove', [LazadaProductGroupController::class, 'massRemove'])->whereNumber('id')->name('ext.lazada.product-groups.massRemove');
        Route::post('/lazada/{store}/product-groups/{id}/products/delete-from-lazada', [LazadaProductGroupController::class, 'deleteFromLazada'])->whereNumber('id')->name('ext.lazada.product-groups.deleteFromLazada');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/sync-id', [LazadaProductGroupController::class, 'syncId'])->whereNumber('id')->name('ext.lazada.product-groups.syncId');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/unlink', [LazadaProductGroupController::class, 'unlinkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.unlinkProduct');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/link', [LazadaProductGroupController::class, 'linkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.linkProduct');
        Route::delete('/lazada/{store}/product-groups/{id}/products/{productId}', [LazadaProductGroupController::class, 'removeProduct'])->whereNumber('id')->name('ext.lazada.product-groups.removeProduct');
        Route::post('/lazada/{store}/product-groups/{id}/template/sync', [LazadaProductGroupController::class, 'syncTemplate'])->whereNumber('id')->name('ext.lazada.product-groups.template_sync');
        Route::post('/lazada/{store}/product-groups/refresh-categories', [LazadaProductGroupController::class, 'refreshCategories'])->name('ext.lazada.product-groups.refreshCategories');
        Route::post('/lazada/{store}/product-groups/fetch-attributes', [LazadaProductGroupController::class, 'fetchAttributesAjax'])->name('ext.lazada.product-groups.fetchAttributes');

        // Lazada Products — writes
        Route::get('/lazada/{store}/products/create', [LazadaProductController::class, 'create'])->name('ext.lazada.products.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/lazada/{store}/products', [LazadaProductController::class, 'store'])->name('ext.lazada.products.store');
        Route::get('/lazada/{store}/products/{id}/edit', [LazadaProductController::class, 'edit'])->whereNumber('id')->name('ext.lazada.products.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/lazada/{store}/products/{id}', [LazadaProductController::class, 'update'])->whereNumber('id')->name('ext.lazada.products.update');
        Route::post('/lazada/{store}/products/{id}/template/sync', [LazadaProductController::class, 'syncTemplate'])->whereNumber('id')->name('ext.lazada.products.template_sync');
        Route::post('/lazada/{store}/products/{id}/attributes', [LazadaProductController::class, 'saveAttributes'])->whereNumber('id')->name('ext.lazada.products.attributes_save');
        Route::post('/lazada/{store}/products/{id}/variants', [LazadaProductController::class, 'saveVariants'])->whereNumber('id')->name('ext.lazada.products.variants_save');
        Route::post('/lazada/{store}/products/{id}/brands/sync', [LazadaProductController::class, 'syncBrands'])->whereNumber('id')->name('ext.lazada.products.brands_sync');
        Route::post('/lazada/{store}/products/{id}/sync/quantity', [LazadaProductController::class, 'syncQuantity'])->whereNumber('id')->name('ext.lazada.products.sync_quantity');
        Route::post('/lazada/{store}/products/{id}/sync/price', [LazadaProductController::class, 'syncPrice'])->whereNumber('id')->name('ext.lazada.products.sync_price');
        Route::post('/lazada/{store}/products/{id}/upload', [LazadaProductController::class, 'uploadToLazada'])->whereNumber('id')->name('ext.lazada.products.upload');
        Route::post('/lazada/{store}/products/{id}/delete/lazada', [LazadaProductController::class, 'deleteFromLazada'])->whereNumber('id')->name('ext.lazada.products.delete_lazada');
        Route::post('/lazada/{store}/products/{id}/unlink', [LazadaProductController::class, 'unlink'])->whereNumber('id')->name('ext.lazada.products.unlink');
        Route::post('/lazada/{store}/products/{id}/remove', [LazadaProductController::class, 'removeFromList'])->whereNumber('id')->name('ext.lazada.products.remove');
        Route::post('/lazada/{store}/products/{id}/sync/lazada-id', [LazadaProductController::class, 'syncLazadaId'])->whereNumber('id')->name('ext.lazada.products.sync_lazada_id');

        // Lazada Products — bulk writes
        // Stateless import (the owner, 2026-09-04): the page's GET carries ?fetch=1
        // to read the store live; these act on the values the page carries.
        Route::post('/lazada/{store}/products/import-one', [LazadaProductController::class, 'importOne'])->name('ext.lazada.products.import_one');
        Route::post('/lazada/{store}/products/import-selected', [LazadaProductController::class, 'importSelected'])->name('ext.lazada.products.import_selected');
        Route::post('/lazada/{store}/products/link-item', [LazadaProductController::class, 'linkItem'])->name('ext.lazada.products.link_item');
        // Add products to this store's list (#201): a catalogue product joins
        // the store, unpushed, ready to configure and push from This store.
        Route::post('/lazada/{store}/products/add-to-store', [LazadaProductController::class, 'addToStoreBulk'])->name('ext.lazada.products.add_to_store_bulk');
        Route::post('/lazada/{store}/products/{productId}/add-to-store', [LazadaProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.lazada.products.add_to_store');
        Route::post('/lazada/{store}/products/bulk/upload', [LazadaProductController::class, 'bulkUploadToLazada'])->name('ext.lazada.products.bulk_upload');
        Route::post('/lazada/{store}/listings/{id}/toggle', [LazadaProductController::class, 'toggleListing'])->whereNumber('id')->name('ext.lazada.listings.toggle');
        // Activate / deactivate the selection, and refresh the status mirror on demand.
        Route::post('/lazada/{store}/products/bulk/toggle', [LazadaProductController::class, 'bulkToggle'])->name('ext.lazada.products.bulk_toggle');
        Route::post('/lazada/{store}/products/refresh-status', [LazadaProductController::class, 'refreshStatus'])->name('ext.lazada.products.refresh_status');
        Route::post('/lazada/{store}/listings/{id}/push-discount', [LazadaProductController::class, 'pushDiscount'])->whereNumber('id')->name('ext.lazada.listings.push_discount');

        // Voucher writes (#153): create, deactivate. Pass-throughs; Lazada owns the object.
        Route::post('/lazada/{store}/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'store'])->name('ext.lazada.vouchers.store');
        Route::post('/lazada/{store}/vouchers/{voucherId}/deactivate', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'deactivate'])->where('voucherId', '[A-Za-z0-9_-]+')->name('ext.lazada.vouchers.deactivate');
        Route::post('/lazada/{store}/products/check', [LazadaProductController::class, 'checkAgainstLazada'])->name('ext.lazada.products.check');
        Route::post('/lazada/{store}/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('ext.lazada.products.bulk_sync_quantity');
        Route::post('/lazada/{store}/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('ext.lazada.products.bulk_sync_price');
        Route::post('/lazada/{store}/products/bulk/sync/lazada-id', [LazadaProductController::class, 'bulkSyncLazadaId'])->name('ext.lazada.products.bulk_sync_lazada_id');
        Route::post('/lazada/{store}/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('ext.lazada.products.bulk_delete');

        // Lazada Products — sample push, unmatched workflow
        Route::post('/lazada/{store}/products/{id}/push/sample', [LazadaProductController::class, 'pushSample'])->whereNumber('id')->name('ext.lazada.products.push_sample');

        // Brands + Categories — fetches that pull from Lazada and persist locally
        Route::post('/lazada/{store}/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('ext.lazada.brands_sync');
        Route::post('/lazada/{store}/brands/fetch', [LazadaBrandController::class, 'fetch'])->name('ext.lazada.brands.fetch');
        Route::post('/lazada/{store}/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('ext.lazada.categories.fetch');
        Route::post('/lazada/{store}/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.fetch');
    });

    // ── Lazada Orders — READ (browse, view show pages, download docs) ──
    Route::group([], function () {
        Route::get('/lazada/{store}/orders', [LazadaOrderController::class, 'index'])->name('ext.lazada.orders.index');
        Route::get('/lazada/{store}/orders/returns', [LazadaOrderController::class, 'returns'])->name('ext.lazada.orders.returns');
        Route::get('/lazada/{store}/orders/{orderId}', [LazadaOrderController::class, 'show'])->name('ext.lazada.orders.show');
        Route::get('/lazada/{store}/orders/{orderId}/awb', [LazadaOrderController::class, 'awbPdf'])->name('ext.lazada.orders.awb');
        // POST because it carries a list of ids, not because it writes. Read tier.
        Route::post('/lazada/{store}/orders/bulk-awb', [LazadaOrderController::class, 'bulkAwb'])
            ->name('ext.lazada.orders.bulk_awb')
            ->defaults('permission_tier', 'view');
        Route::get('/lazada/{store}/orders/{orderId}/packing-list', [LazadaOrderController::class, 'packingList'])->name('ext.lazada.orders.packing_list');
        Route::get('/lazada/{store}/orders/{orderId}/pick-list', [LazadaOrderController::class, 'pickList'])->name('ext.lazada.orders.pick_list');
        Route::get('/lazada/{store}/orders/{orderId}/logistics-trace', [LazadaOrderController::class, 'logisticsTrace'])->name('ext.lazada.orders.logistics_trace');
        Route::get('/lazada/{store}/orders/{orderId}/cancel-reasons', [LazadaOrderController::class, 'cancelReasons'])->name('ext.lazada.orders.cancel_reasons');
        Route::get('/lazada/{store}/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrint'])->name('ext.lazada.orders.ship_print');
    });

    // ── Lazada Orders — WRITE (sync, pack, ship, cancel) ───────
    Route::group([], function () {
        Route::post('/lazada/{store}/orders/returns/fetch', [LazadaOrderController::class, 'fetchReturns'])->name('ext.lazada.orders.fetch_returns');
        Route::post('/lazada/{store}/orders/fetch', [LazadaOrderController::class, 'fetch'])->name('ext.lazada.orders.fetch');
        Route::post('/lazada/{store}/orders/update-statuses', [LazadaOrderController::class, 'updateStatuses'])->name('ext.lazada.orders.update_statuses');
        Route::post('/lazada/{store}/orders/reset', [LazadaOrderController::class, 'reset'])->name('ext.lazada.orders.reset');
        Route::post('/lazada/{store}/orders/{orderId}/pack', [LazadaOrderController::class, 'pack'])->name('ext.lazada.orders.pack');
        Route::post('/lazada/{store}/orders/{orderId}/pack-print', [LazadaOrderController::class, 'packAndPrint'])->name('ext.lazada.orders.pack_print');
        Route::post('/lazada/{store}/orders/bulk-pack-print', [LazadaOrderController::class, 'bulkPackPrint'])->name('ext.lazada.orders.bulk_pack_print');
        Route::post('/lazada/{store}/orders/{orderId}/recreate-package', [LazadaOrderController::class, 'recreatePackage'])->name('ext.lazada.orders.recreate_package');
        Route::post('/lazada/{store}/orders/{orderId}/rts', [LazadaOrderController::class, 'rts'])->name('ext.lazada.orders.rts');
        Route::post('/lazada/{store}/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrintPost'])->name('ext.lazada.orders.ship_print_post');
        Route::post('/lazada/{store}/orders/{orderId}/cancel', [LazadaOrderController::class, 'cancel'])->name('ext.lazada.orders.cancel');
    });

});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/lazada was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Lazada operator lands
// on had no entry in the permission editor. The route lives here now, so the
// page derives lazada/dashboard and is enforced at the door.
//
// The digits-only {store} tail keeps this from swallowing
// /channels/lazada/orders - same rule the old wildcard relied on. A store
// suffix that resolves to no card is a 404.
Route::middleware(['auth', \Extensions\lazada\Http\ResolveLazadaStore::class])->prefix('channels')->group(function () {
    Route::get('/lazada/{store?}', [\Extensions\lazada\Controllers\LazadaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.lazada.dashboard');
});

// ── LEGACY STORELESS URLS ───────────────────────────────────────────────
//
// Multi-store (the owner 2026-09-05): every Lazada page now lives under
// /channels/lazada/{store}/… . Bookmarks and muscle memory from the
// single-store era arrive without the segment; a GET is redirected to the
// same page on the default store, query string intact. Registered after the
// dashboard so /channels/lazada/{digits} keeps meaning a dashboard.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/lazada/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\lazada\Models\LazadaSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/lazada/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.lazada.legacy_storeless');
});
