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

// ── OAUTH SURFACES: EXEMPT FROM THE /integrations PREFIX ────────────────
//
// IA migration (2026-08-08, Task 2). Every other page in this file moved
// under /integrations/lazada/…; these two keep their URIs permanently.
//
// Lazada OAuth callback — must be publicly accessible so Lazada can redirect
// back with the `code` query param. Kept outside the auth group so the code
// isn't lost behind a login redirect. Lives in the extension (not core) so
// removing the Lazada extension cleanly removes the route too.
//
// LazadaController::computedRedirectUri() returns route('lazada.callback'),
// and that exact string is registered in the Lazada Open Platform console and
// compared byte-for-byte on every consent round trip. A 301 cannot rescue it:
// the value is checked, not followed. ext.lazada.authorize is exempted with
// it per the migration ledger's pre-dispatch constraint.
Route::get('/lazada/callback', [LazadaSettingsController::class, 'callback'])->name('lazada.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/lazada/authorize', [LazadaSettingsController::class, 'redirectToAuth'])->name('ext.lazada.authorize')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
});

// ── Tier-aware permission map (Phase 3 of view/manage split) ────────────
//
// Read routes (display-only, no DB write, no marketplace API write):
//   gated by 'view_lazada|manage_lazada' — granting EITHER tier suffices.
//   The pipe-OR syntax is honored by App\Http\Middleware\EnsurePermission.
//
// Write routes (any mutation: DB write, OAuth, sync push, marketplace state
// change like pack/ship/cancel/delete, status-map save):
//   gated by 'manage_lazada' (or 'manage_lazada_orders' inside the orders
//   block) — read-only operators MUST NOT trigger these.
//
// A few endpoints look like writes (POST) but are pure read computations:
//   - product/payload-preview        → builds a payload string, no persistence
//   - products/{id}/payload/build    → ditto, returns the built payload
//   - search-catalog                 → GET, search index lookup
// These are classified as READ. Everything else POST/PUT/DELETE is WRITE.
//
// Everything below moves under /integrations. Route NAMES are unchanged; the
// `/lazada/…` literals inside the group are now the channel segment beneath
// that prefix, so `/lazada/orders` reads as `/integrations/lazada/orders`.
Route::middleware(['auth'])->prefix('channels')->group(function () {

    // ── Lazada Settings + Catalog management ────────────────────
    Route::group([], function () {
        // Settings page (renders the screen, no writes).
        //
        // `/lazada/settings`, not a bare `/lazada`: /integrations/lazada is
        // the workspace Overview, registered in routes/web.php ahead of every
        // extension route. A bare channel segment here would be dead on
        // arrival - the Overview would answer it and this page would never be
        // reachable. Naming the settings page explicitly is also what the
        // slash grammar asks for, and matches Venta and OpenCart, whose
        // settings pages have always carried the segment.
        Route::get('/lazada/settings', [LazadaSettingsController::class, 'index'])->name('ext.lazada.index');

        // Catalog browsing (lists, search)
        Route::get('/lazada/products', [LazadaProductController::class, 'index'])->name('ext.lazada.products.index');

        // Vouchers (#153): a live read of the shop's vouchers.
        Route::get('/lazada/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'index'])->name('ext.lazada.vouchers.index');
        Route::get('/lazada/products/search-catalog', [LazadaProductController::class, 'searchCatalogProducts'])->name('ext.lazada.products.search_catalog');
        Route::get('/lazada/brands', [LazadaBrandController::class, 'index'])->name('ext.lazada.brands.index');
        Route::get('/lazada/brands/autocomplete', [LazadaBrandController::class, 'autocomplete'])->name('ext.lazada.brands.autocomplete');
        Route::get('/lazada/categories', [LazadaCategoryController::class, 'index'])->name('ext.lazada.categories.index');
        Route::get('/lazada/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.show');

        // Product Groups — read-only list + edit-form RENDER
        // NOTE: edit form GET is a read; save (PUT) is a write — see below.
        Route::get('/lazada/product-groups', [LazadaProductGroupController::class, 'index'])->name('ext.lazada.product-groups.index');
        Route::get('/lazada/product-groups/search-products', [LazadaProductGroupController::class, 'searchProducts'])->name('ext.lazada.product-groups.searchProducts');
        Route::get('/lazada/product-groups/{id}/edit', [LazadaProductGroupController::class, 'edit'])->whereNumber('id')->name('ext.lazada.product-groups.edit');
        Route::get('/lazada/product-groups/{id}/products', [LazadaProductGroupController::class, 'products'])->whereNumber('id')->name('ext.lazada.product-groups.products');
        // Read tier: asks Lazada what it holds and prints the answer, adopting nothing.
        Route::get('/lazada/product-groups/{id}/orphans', [LazadaProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.lazada.product-groups.orphans');

        // Payload preview / build — POST but pure compute (no persistence, no
        // marketplace call). Surfaces what WOULD be sent so an operator can
        // audit before a write user actually pushes. Read-only.
        Route::post('/lazada/product/payload-preview', [LazadaApiExplorerController::class, 'productPayloadPreview'])
            ->name('ext.lazada.product_payload_preview')
            /* A POST that reads. It builds and returns the payload we WOULD
               send, changing nothing, and is a POST only because a product id
               list does not fit a query string. Without this the verb alone
               would put it behind the manage tier and take a preview away
               from the people most likely to want one. */
            ->defaults('permission_tier', 'view');
        Route::post('/lazada/products/{id}/payload/build', [LazadaProductController::class, 'buildPayload'])->whereNumber('id')->name('ext.lazada.products.payload_build');
    });

    // ── Lazada Settings + Catalog WRITES ────────────────────────
    Route::group([], function () {
        // OAuth / token / mode. ext.lazada.authorize is NOT here: it is one of
        // the two exempt OAuth surfaces registered above, outside this prefix.
        Route::post('/lazada/save', [LazadaSettingsController::class, 'save'])->name('ext.lazada.save');
        Route::post('/lazada/toggle-mode', [LazadaSettingsController::class, 'toggleMode'])->name('ext.lazada.toggle_mode');
        Route::post('/lazada/token/create', [LazadaSettingsController::class, 'tokenCreate'])->name('ext.lazada.token_create');
        Route::post('/lazada/token/refresh', [LazadaSettingsController::class, 'tokenRefresh'])->name('ext.lazada.token_refresh');
        Route::post('/lazada/sandbox-token/save', [LazadaSettingsController::class, 'saveSandboxToken'])->name('ext.lazada.sandbox_token_save');

        // Status-map saves
        Route::post('/lazada/order-status-map', [LazadaSettingsController::class, 'saveOrderStatusMap'])->name('ext.lazada.order_status_map');
        Route::post('/lazada/reverse-status-map', [LazadaSettingsController::class, 'saveReverseStatusMap'])->name('ext.lazada.reverse_status_map');

        // API logging admin
        Route::post('/lazada/toggle-logging', [LazadaSettingsController::class, 'toggleLogging'])->name('ext.lazada.toggle_logging');
        Route::delete('/lazada/api-logs', [LazadaSettingsController::class, 'clearApiLogs'])->name('ext.lazada.clear_api_logs');
        Route::post('/lazada/purge-raw', [LazadaSettingsController::class, 'purgeRaw'])->name('ext.lazada.purge_raw');

        // API Explorer — these call the live Lazada API (state-changing on some
        // endpoints), even when the operator picks a "GET" route in the UI.
        // Treat the explorer as a privileged write tool.
        Route::post('/lazada/call', [LazadaApiExplorerController::class, 'callApi'])->name('ext.lazada.call_api');
        Route::post('/lazada/explorer/run', [LazadaApiExplorerController::class, 'explorerRun'])->name('ext.lazada.explorer_run');
        Route::post('/lazada/packs/run', [LazadaApiExplorerController::class, 'packsRun'])->name('ext.lazada.packs_run');

        // Lazada Catalog POC — fetch endpoints (call the marketplace API and
        // typically persist results). Write tier.
        Route::post('/lazada/category/tree', [LazadaApiExplorerController::class, 'categoryTree'])->name('ext.lazada.category_tree');
        Route::post('/lazada/category/attributes', [LazadaApiExplorerController::class, 'categoryAttributes'])->name('ext.lazada.category_attributes');
        Route::post('/lazada/category/brands', [LazadaApiExplorerController::class, 'brandsQuery'])->name('ext.lazada.brands_query');
        Route::post('/lazada/products/get', [LazadaApiExplorerController::class, 'productsGet'])->name('ext.lazada.products_get');
        Route::post('/lazada/product/create', [LazadaApiExplorerController::class, 'productCreate'])->name('ext.lazada.product_create');
        Route::post('/lazada/orders/get', [LazadaApiExplorerController::class, 'ordersGet'])->name('ext.lazada.orders_get');
        Route::post('/lazada/order/items/get', [LazadaApiExplorerController::class, 'orderItemsGet'])->name('ext.lazada.order_items_get');
        Route::post('/lazada/order/awb/pdf', [LazadaApiExplorerController::class, 'awbPdfGet'])->name('ext.lazada.awb_pdf_get');

        // Lazada Product Groups — writes
        Route::get('/lazada/product-groups/create', [LazadaProductGroupController::class, 'create'])->name('ext.lazada.product-groups.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::post('/lazada/product-groups', [LazadaProductGroupController::class, 'store'])->name('ext.lazada.product-groups.store');
        Route::put('/lazada/product-groups/{id}', [LazadaProductGroupController::class, 'update'])->whereNumber('id')->name('ext.lazada.product-groups.update');
        Route::delete('/lazada/product-groups/{id}', [LazadaProductGroupController::class, 'destroy'])->whereNumber('id')->name('ext.lazada.product-groups.destroy');
        Route::post('/lazada/product-groups/{id}/products/add', [LazadaProductGroupController::class, 'addProducts'])->whereNumber('id')->name('ext.lazada.product-groups.addProducts');
        Route::post('/lazada/product-groups/{id}/products/check', [LazadaProductGroupController::class, 'checkAgainstLazada'])->whereNumber('id')->name('ext.lazada.product-groups.check');
        Route::post('/lazada/product-groups/{id}/products/push', [LazadaProductGroupController::class, 'push'])->whereNumber('id')->name('ext.lazada.product-groups.push');
        Route::post('/lazada/product-groups/{id}/products/update-product', [LazadaProductGroupController::class, 'updateProduct'])->whereNumber('id')->name('ext.lazada.product-groups.updateProduct');
        Route::post('/lazada/product-groups/{id}/products/push-prices', [LazadaProductGroupController::class, 'pushPrices'])->whereNumber('id')->name('ext.lazada.product-groups.pushPrices');
        Route::post('/lazada/product-groups/{id}/products/push-stock', [LazadaProductGroupController::class, 'pushStock'])->whereNumber('id')->name('ext.lazada.product-groups.pushStock');
        Route::post('/lazada/product-groups/{id}/products/mass-remove', [LazadaProductGroupController::class, 'massRemove'])->whereNumber('id')->name('ext.lazada.product-groups.massRemove');
        Route::post('/lazada/product-groups/{id}/products/delete-from-lazada', [LazadaProductGroupController::class, 'deleteFromLazada'])->whereNumber('id')->name('ext.lazada.product-groups.deleteFromLazada');
        Route::post('/lazada/product-groups/{id}/products/{productId}/sync-id', [LazadaProductGroupController::class, 'syncId'])->whereNumber('id')->name('ext.lazada.product-groups.syncId');
        Route::post('/lazada/product-groups/{id}/products/{productId}/unlink', [LazadaProductGroupController::class, 'unlinkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.unlinkProduct');
        Route::post('/lazada/product-groups/{id}/products/{productId}/link', [LazadaProductGroupController::class, 'linkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.linkProduct');
        Route::delete('/lazada/product-groups/{id}/products/{productId}', [LazadaProductGroupController::class, 'removeProduct'])->whereNumber('id')->name('ext.lazada.product-groups.removeProduct');
        Route::post('/lazada/product-groups/{id}/template/sync', [LazadaProductGroupController::class, 'syncTemplate'])->whereNumber('id')->name('ext.lazada.product-groups.template_sync');
        Route::post('/lazada/product-groups/refresh-categories', [LazadaProductGroupController::class, 'refreshCategories'])->name('ext.lazada.product-groups.refreshCategories');
        Route::post('/lazada/product-groups/fetch-attributes', [LazadaProductGroupController::class, 'fetchAttributesAjax'])->name('ext.lazada.product-groups.fetchAttributes');

        // Lazada Products — writes
        Route::get('/lazada/products/create', [LazadaProductController::class, 'create'])->name('ext.lazada.products.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::post('/lazada/products', [LazadaProductController::class, 'store'])->name('ext.lazada.products.store');
        Route::get('/lazada/products/{id}/edit', [LazadaProductController::class, 'edit'])->whereNumber('id')->name('ext.lazada.products.edit')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::put('/lazada/products/{id}', [LazadaProductController::class, 'update'])->whereNumber('id')->name('ext.lazada.products.update');
        Route::post('/lazada/products/{id}/template/sync', [LazadaProductController::class, 'syncTemplate'])->whereNumber('id')->name('ext.lazada.products.template_sync');
        Route::post('/lazada/products/{id}/attributes', [LazadaProductController::class, 'saveAttributes'])->whereNumber('id')->name('ext.lazada.products.attributes_save');
        Route::post('/lazada/products/{id}/variants', [LazadaProductController::class, 'saveVariants'])->whereNumber('id')->name('ext.lazada.products.variants_save');
        Route::post('/lazada/products/{id}/brands/sync', [LazadaProductController::class, 'syncBrands'])->whereNumber('id')->name('ext.lazada.products.brands_sync');
        Route::post('/lazada/products/{id}/sync/quantity', [LazadaProductController::class, 'syncQuantity'])->whereNumber('id')->name('ext.lazada.products.sync_quantity');
        Route::post('/lazada/products/{id}/sync/price', [LazadaProductController::class, 'syncPrice'])->whereNumber('id')->name('ext.lazada.products.sync_price');
        Route::post('/lazada/products/{id}/upload', [LazadaProductController::class, 'uploadToLazada'])->whereNumber('id')->name('ext.lazada.products.upload');
        Route::post('/lazada/products/{id}/delete/lazada', [LazadaProductController::class, 'deleteFromLazada'])->whereNumber('id')->name('ext.lazada.products.delete_lazada');
        Route::post('/lazada/products/{id}/unlink', [LazadaProductController::class, 'unlink'])->whereNumber('id')->name('ext.lazada.products.unlink');
        Route::post('/lazada/products/{id}/remove', [LazadaProductController::class, 'removeFromList'])->whereNumber('id')->name('ext.lazada.products.remove');
        Route::post('/lazada/products/{id}/sync/lazada-id', [LazadaProductController::class, 'syncLazadaId'])->whereNumber('id')->name('ext.lazada.products.sync_lazada_id');

        // Lazada Products — bulk writes
        Route::post('/lazada/products/bulk/upload', [LazadaProductController::class, 'bulkUploadToLazada'])->name('ext.lazada.products.bulk_upload');
        Route::post('/lazada/listings/{id}/toggle', [LazadaProductController::class, 'toggleListing'])->whereNumber('id')->name('ext.lazada.listings.toggle');
        // Activate / deactivate the selection (the owner, 2026-08-28), and refresh the status mirror on demand.
        Route::post('/lazada/products/bulk/toggle', [LazadaProductController::class, 'bulkToggle'])->name('ext.lazada.products.bulk_toggle');
        Route::post('/lazada/products/refresh-status', [LazadaProductController::class, 'refreshStatus'])->name('ext.lazada.products.refresh_status');
        Route::post('/lazada/listings/{id}/push-discount', [LazadaProductController::class, 'pushDiscount'])->whereNumber('id')->name('ext.lazada.listings.push_discount');

        // Voucher writes (#153): create, deactivate. Pass-throughs; Lazada owns the object.
        Route::post('/lazada/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'store'])->name('ext.lazada.vouchers.store');
        Route::post('/lazada/vouchers/{voucherId}/deactivate', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'deactivate'])->where('voucherId', '[A-Za-z0-9_-]+')->name('ext.lazada.vouchers.deactivate');
        Route::post('/lazada/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('ext.lazada.products.bulk_sync_quantity');
        Route::post('/lazada/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('ext.lazada.products.bulk_sync_price');
        Route::post('/lazada/products/bulk/sync/lazada-id', [LazadaProductController::class, 'bulkSyncLazadaId'])->name('ext.lazada.products.bulk_sync_lazada_id');
        Route::post('/lazada/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('ext.lazada.products.bulk_delete');

        // Lazada Products — sample push, unmatched workflow
        Route::post('/lazada/products/{id}/push/sample', [LazadaProductController::class, 'pushSample'])->whereNumber('id')->name('ext.lazada.products.push_sample');
        Route::post('/lazada/products/unmatched/sync', [LazadaProductController::class, 'syncUnmatchedItems'])->name('ext.lazada.products.sync_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/link', [LazadaProductController::class, 'linkUnmatchedItem'])->name('ext.lazada.products.link_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/dismiss', [LazadaProductController::class, 'dismissUnmatchedItem'])->name('ext.lazada.products.dismiss_unmatched');

        // Brands + Categories — fetches that pull from Lazada and persist locally
        Route::post('/lazada/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('ext.lazada.brands_sync');
        Route::post('/lazada/brands/fetch', [LazadaBrandController::class, 'fetch'])->name('ext.lazada.brands.fetch');
        Route::post('/lazada/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('ext.lazada.categories.fetch');
        Route::post('/lazada/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.fetch');
    });

    // ── Lazada Orders — READ (browse, view show pages, download docs) ──
    // Read-only orders viewer can pull AWB/packing/pick docs because those
    // are server-rendered from already-fetched data, not marketplace pushes.
    Route::group([], function () {
        Route::get('/lazada/orders', [LazadaOrderController::class, 'index'])->name('ext.lazada.orders.index');
        Route::get('/lazada/orders/returns', [LazadaOrderController::class, 'returns'])->name('ext.lazada.orders.returns');
        Route::get('/lazada/orders/{orderId}', [LazadaOrderController::class, 'show'])->name('ext.lazada.orders.show');
        Route::get('/lazada/orders/{orderId}/awb', [LazadaOrderController::class, 'awbPdf'])->name('ext.lazada.orders.awb');
        // POST because it carries a list of ids, not because it writes. Same
        // read tier as the single AWB above: printing a waybill for orders
        // already on file is not a marketplace push.
        Route::post('/lazada/orders/bulk-awb', [LazadaOrderController::class, 'bulkAwb'])
            ->name('ext.lazada.orders.bulk_awb')
            // Fetches AWB PDFs for a list of orders. A read, POSTed because
            // the list is the body. A read-only operator holds it today.
            ->defaults('permission_tier', 'view');
        Route::get('/lazada/orders/{orderId}/packing-list', [LazadaOrderController::class, 'packingList'])->name('ext.lazada.orders.packing_list');
        Route::get('/lazada/orders/{orderId}/pick-list', [LazadaOrderController::class, 'pickList'])->name('ext.lazada.orders.pick_list');
        Route::get('/lazada/orders/{orderId}/logistics-trace', [LazadaOrderController::class, 'logisticsTrace'])->name('ext.lazada.orders.logistics_trace');
        Route::get('/lazada/orders/{orderId}/cancel-reasons', [LazadaOrderController::class, 'cancelReasons'])->name('ext.lazada.orders.cancel_reasons');
        Route::get('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrint'])->name('ext.lazada.orders.ship_print');
    });

    // ── Lazada Orders — WRITE (sync, pack, ship, cancel) ───────
    Route::group([], function () {
        Route::post('/lazada/orders/returns/fetch', [LazadaOrderController::class, 'fetchReturns'])->name('ext.lazada.orders.fetch_returns');
        Route::post('/lazada/orders/fetch', [LazadaOrderController::class, 'fetch'])->name('ext.lazada.orders.fetch');
        Route::post('/lazada/orders/update-statuses', [LazadaOrderController::class, 'updateStatuses'])->name('ext.lazada.orders.update_statuses');
        Route::post('/lazada/orders/reset', [LazadaOrderController::class, 'reset'])->name('ext.lazada.orders.reset');
        Route::post('/lazada/orders/{orderId}/pack', [LazadaOrderController::class, 'pack'])->name('ext.lazada.orders.pack');
        Route::post('/lazada/orders/{orderId}/pack-print', [LazadaOrderController::class, 'packAndPrint'])->name('ext.lazada.orders.pack_print');
        Route::post('/lazada/orders/bulk-pack-print', [LazadaOrderController::class, 'bulkPackPrint'])->name('ext.lazada.orders.bulk_pack_print');
        Route::post('/lazada/orders/{orderId}/recreate-package', [LazadaOrderController::class, 'recreatePackage'])->name('ext.lazada.orders.recreate_package');
        Route::post('/lazada/orders/{orderId}/rts', [LazadaOrderController::class, 'rts'])->name('ext.lazada.orders.rts');
        Route::post('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrintPost'])->name('ext.lazada.orders.ship_print_post');
        Route::post('/lazada/orders/{orderId}/cancel', [LazadaOrderController::class, 'cancel'])->name('ext.lazada.orders.cancel');
    });

});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/lazada was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Lazada operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// lazada/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/lazada/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/lazada/{store?}', [\Extensions\lazada\Controllers\LazadaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.lazada.dashboard');
});
