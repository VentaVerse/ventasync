<?php

use Extensions\lazada\Controllers\LazadaController;
use Extensions\lazada\Controllers\LazadaProductController;
use Extensions\lazada\Controllers\LazadaCategoryController;
use Extensions\lazada\Controllers\LazadaCategoryAttributeController;
use Extensions\lazada\Controllers\LazadaOrderController;
use Extensions\lazada\Controllers\LazadaProductGroupController;
use Extensions\lazada\Controllers\LazadaBrandController;
use Illuminate\Support\Facades\Route;

// Lazada OAuth callback — must be publicly accessible so Lazada can redirect
// back with the `code` query param. Kept outside the auth group so the code
// isn't lost behind a login redirect. Lives in the extension (not core) so
// removing the Lazada extension cleanly removes the route too.
Route::get('/lazada/callback', [LazadaController::class, 'callback'])->name('lazada.callback');

Route::middleware(['auth'])->group(function () {

    // Lazada — settings, catalog POC, brands, categories, product groups, products
    Route::middleware(['perm:manage_lazada'])->group(function () {
        Route::get('/lazada', [LazadaController::class, 'index'])->name('ext.lazada.index');
        Route::get('/lazada/authorize', [LazadaController::class, 'redirectToAuth'])->name('ext.lazada.authorize');
        Route::post('/lazada/save', [LazadaController::class, 'save'])->name('ext.lazada.save');
        Route::post('/lazada/toggle-mode', [LazadaController::class, 'toggleMode'])->name('ext.lazada.toggle_mode');
        Route::post('/lazada/order-status-map', [LazadaController::class, 'saveOrderStatusMap'])->name('ext.lazada.order_status_map');
        Route::post('/lazada/sync-days', [LazadaController::class, 'saveSyncDays'])->name('ext.lazada.sync_days');
        Route::post('/lazada/sync-days-returns', [LazadaController::class, 'saveSyncDaysReturns'])->name('ext.lazada.sync_days_returns');
        Route::post('/lazada/reverse-status-map', [LazadaController::class, 'saveReverseStatusMap'])->name('ext.lazada.reverse_status_map');
        Route::post('/lazada/toggle-logging', [LazadaController::class, 'toggleLogging'])->name('ext.lazada.toggle_logging');
        Route::delete('/lazada/api-logs', [LazadaController::class, 'clearApiLogs'])->name('ext.lazada.clear_api_logs');
        Route::post('/lazada/token/create', [LazadaController::class, 'tokenCreate'])->name('ext.lazada.token_create');
        Route::post('/lazada/token/refresh', [LazadaController::class, 'tokenRefresh'])->name('ext.lazada.token_refresh');
        Route::post('/lazada/call', [LazadaController::class, 'callApi'])->name('ext.lazada.call_api');
        Route::post('/lazada/explorer/run', [LazadaController::class, 'explorerRun'])->name('ext.lazada.explorer_run');
        Route::post('/lazada/packs/run', [LazadaController::class, 'packsRun'])->name('ext.lazada.packs_run');

        // Lazada Catalog POC
        Route::post('/lazada/category/tree', [LazadaController::class, 'categoryTree'])->name('ext.lazada.category_tree');
        Route::post('/lazada/category/attributes', [LazadaController::class, 'categoryAttributes'])->name('ext.lazada.category_attributes');
        Route::post('/lazada/category/brands', [LazadaController::class, 'brandsQuery'])->name('ext.lazada.brands_query');
        Route::post('/lazada/products/get', [LazadaController::class, 'productsGet'])->name('ext.lazada.products_get');

        Route::post('/lazada/product/payload-preview', [LazadaController::class, 'productPayloadPreview'])->name('ext.lazada.product_payload_preview');
        Route::post('/lazada/product/create', [LazadaController::class, 'productCreate'])->name('ext.lazada.product_create');
        Route::post('/lazada/orders/get', [LazadaController::class, 'ordersGet'])->name('ext.lazada.orders_get');
        Route::post('/lazada/order/items/get', [LazadaController::class, 'orderItemsGet'])->name('ext.lazada.order_items_get');
        Route::post('/lazada/order/awb/pdf', [LazadaController::class, 'awbPdfGet'])->name('ext.lazada.awb_pdf_get');

        // Lazada Product Groups
        Route::get('/lazada/product-groups', [LazadaProductGroupController::class, 'index'])->name('ext.lazada.product-groups.index');
        Route::get('/lazada/product-groups/create', [LazadaProductGroupController::class, 'create'])->name('ext.lazada.product-groups.create');
        Route::post('/lazada/product-groups', [LazadaProductGroupController::class, 'store'])->name('ext.lazada.product-groups.store');
        Route::get('/lazada/product-groups/search-products', [LazadaProductGroupController::class, 'searchProducts'])->name('ext.lazada.product-groups.searchProducts');
        Route::get('/lazada/product-groups/{id}/edit', [LazadaProductGroupController::class, 'edit'])->whereNumber('id')->name('ext.lazada.product-groups.edit');
        Route::put('/lazada/product-groups/{id}', [LazadaProductGroupController::class, 'update'])->whereNumber('id')->name('ext.lazada.product-groups.update');
        Route::delete('/lazada/product-groups/{id}', [LazadaProductGroupController::class, 'destroy'])->whereNumber('id')->name('ext.lazada.product-groups.destroy');
        Route::get('/lazada/product-groups/{id}/products', [LazadaProductGroupController::class, 'products'])->whereNumber('id')->name('ext.lazada.product-groups.products');
        Route::post('/lazada/product-groups/{id}/products/add', [LazadaProductGroupController::class, 'addProducts'])->whereNumber('id')->name('ext.lazada.product-groups.addProducts');
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

        // Lazada Products
        Route::get('/lazada/products', [LazadaProductController::class, 'index'])->name('ext.lazada.products.index');
        Route::get('/lazada/products/create', [LazadaProductController::class, 'create'])->name('ext.lazada.products.create');
        Route::post('/lazada/products', [LazadaProductController::class, 'store'])->name('ext.lazada.products.store');
        Route::get('/lazada/products/{id}/edit', [LazadaProductController::class, 'edit'])->whereNumber('id')->name('ext.lazada.products.edit');
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

        Route::post('/lazada/products/bulk/upload', [LazadaProductController::class, 'bulkUploadToLazada'])->name('ext.lazada.products.bulk_upload');
        Route::post('/lazada/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('ext.lazada.products.bulk_sync_quantity');
        Route::post('/lazada/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('ext.lazada.products.bulk_sync_price');
        Route::post('/lazada/products/bulk/sync/lazada-id', [LazadaProductController::class, 'bulkSyncLazadaId'])->name('ext.lazada.products.bulk_sync_lazada_id');
        Route::post('/lazada/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('ext.lazada.products.bulk_delete');

        Route::post('/lazada/products/{id}/payload/build', [LazadaProductController::class, 'buildPayload'])->whereNumber('id')->name('ext.lazada.products.payload_build');
        Route::post('/lazada/products/{id}/push/sample', [LazadaProductController::class, 'pushSample'])->whereNumber('id')->name('ext.lazada.products.push_sample');

        Route::post('/lazada/products/unmatched/sync', [LazadaProductController::class, 'syncUnmatchedItems'])->name('ext.lazada.products.sync_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/link', [LazadaProductController::class, 'linkUnmatchedItem'])->name('ext.lazada.products.link_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/dismiss', [LazadaProductController::class, 'dismissUnmatchedItem'])->name('ext.lazada.products.dismiss_unmatched');
        Route::get('/lazada/products/search-catalog', [LazadaProductController::class, 'searchCatalogProducts'])->name('ext.lazada.products.search_catalog');

        Route::post('/lazada/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('ext.lazada.brands_sync');

        Route::get('/lazada/brands', [LazadaBrandController::class, 'index'])->name('ext.lazada.brands.index');
        Route::post('/lazada/brands/fetch', [LazadaBrandController::class, 'fetch'])->name('ext.lazada.brands.fetch');
        Route::get('/lazada/brands/autocomplete', [LazadaBrandController::class, 'autocomplete'])->name('ext.lazada.brands.autocomplete');

        Route::get('/lazada/categories', [LazadaCategoryController::class, 'index'])->name('ext.lazada.categories.index');
        Route::post('/lazada/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('ext.lazada.categories.fetch');

        Route::get('/lazada/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.show');

        Route::post('/lazada/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.fetch');
    });

    // Lazada Orders (separate permission)
    Route::middleware(['perm:manage_lazada_orders'])->group(function () {
        Route::get('/lazada/orders', [LazadaOrderController::class, 'index'])->name('ext.lazada.orders.index');
        Route::get('/lazada/orders/returns', [LazadaOrderController::class, 'returns'])->name('ext.lazada.orders.returns');
        Route::post('/lazada/orders/returns/fetch', [LazadaOrderController::class, 'fetchReturns'])->name('ext.lazada.orders.fetch_returns');
        Route::get('/lazada/orders/{orderId}', [LazadaOrderController::class, 'show'])->name('ext.lazada.orders.show');
        Route::post('/lazada/orders/fetch', [LazadaOrderController::class, 'fetch'])->name('ext.lazada.orders.fetch');
        Route::post('/lazada/orders/update-statuses', [LazadaOrderController::class, 'updateStatuses'])->name('ext.lazada.orders.update_statuses');
        Route::post('/lazada/orders/reset', [LazadaOrderController::class, 'reset'])->name('ext.lazada.orders.reset');
        Route::post('/lazada/orders/{orderId}/pack', [LazadaOrderController::class, 'pack'])->name('ext.lazada.orders.pack');
        Route::post('/lazada/orders/{orderId}/pack-print', [LazadaOrderController::class, 'packAndPrint'])->name('ext.lazada.orders.pack_print');
        Route::post('/lazada/orders/{orderId}/recreate-package', [LazadaOrderController::class, 'recreatePackage'])->name('ext.lazada.orders.recreate_package');
        Route::post('/lazada/orders/{orderId}/rts', [LazadaOrderController::class, 'rts'])->name('ext.lazada.orders.rts');
        Route::get('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrint'])->name('ext.lazada.orders.ship_print');
        Route::post('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrintPost'])->name('ext.lazada.orders.ship_print_post');
        Route::get('/lazada/orders/{orderId}/awb', [LazadaOrderController::class, 'awbPdf'])->name('ext.lazada.orders.awb');
        Route::get('/lazada/orders/{orderId}/packing-list', [LazadaOrderController::class, 'packingList'])->name('ext.lazada.orders.packing_list');
        Route::get('/lazada/orders/{orderId}/pick-list', [LazadaOrderController::class, 'pickList'])->name('ext.lazada.orders.pick_list');
        Route::get('/lazada/orders/{orderId}/logistics-trace', [LazadaOrderController::class, 'logisticsTrace'])->name('ext.lazada.orders.logistics_trace');
        Route::get('/lazada/orders/{orderId}/cancel-reasons', [LazadaOrderController::class, 'cancelReasons'])->name('ext.lazada.orders.cancel_reasons');
        Route::post('/lazada/orders/{orderId}/cancel', [LazadaOrderController::class, 'cancel'])->name('ext.lazada.orders.cancel');
    });

});
