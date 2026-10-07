<?php

use Extensions\lazada\Controllers\LazadaDescriptionTemplateController;
use Extensions\lazada\Controllers\LazadaWatermarkTemplateController;
use Extensions\lazada\Controllers\LazadaSettingsController;
use Extensions\lazada\Controllers\LazadaApiExplorerController;
use Extensions\lazada\Controllers\LazadaProductController;
use Extensions\lazada\Controllers\LazadaCategoryController;
use Extensions\lazada\Controllers\LazadaCategoryAttributeController;
use Extensions\lazada\Controllers\LazadaOrderController;
use Extensions\lazada\Controllers\LazadaProductGroupController;
use Extensions\lazada\Controllers\LazadaBrandController;
use Illuminate\Support\Facades\Route;

Route::get('/lazada/callback', [LazadaSettingsController::class, 'callback'])->name('lazada.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/lazada/authorize', [LazadaSettingsController::class, 'redirectToAuth'])->name('ext.lazada.authorize')
        ->defaults('permission_tier', 'manage');
});

Route::middleware(['auth', \Extensions\lazada\Http\ResolveLazadaStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    Route::group([], function () {
        Route::post('/lazada/stores', [LazadaSettingsController::class, 'createStore'])->name('ext.lazada.stores.store');

        Route::get('/lazada/{store}/settings', [LazadaSettingsController::class, 'index'])->name('ext.lazada.index');

        Route::get('/lazada/{store}/products', [LazadaProductController::class, 'index'])->name('ext.lazada.products.index');
        Route::get('/lazada/{store}/products/import', [LazadaProductController::class, 'importPage'])->name('ext.lazada.products.import');

        Route::get('/lazada/{store}/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'index'])->name('ext.lazada.vouchers.index');
        Route::get('/lazada/{store}/products/search-catalog', [LazadaProductController::class, 'searchCatalogProducts'])->name('ext.lazada.products.search_catalog');
        Route::get('/lazada/{store}/brands', [LazadaBrandController::class, 'index'])->name('ext.lazada.brands.index');
        Route::get('/lazada/{store}/brands/autocomplete', [LazadaBrandController::class, 'autocomplete'])->name('ext.lazada.brands.autocomplete');
        Route::get('/lazada/{store}/categories', [LazadaCategoryController::class, 'index'])->name('ext.lazada.categories.index');
        Route::get('/lazada/{store}/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.show');

        Route::get('/lazada/{store}/product-groups', [LazadaProductGroupController::class, 'index'])->name('ext.lazada.product-groups.index');
        
        Route::get('/lazada/{store}/description-templates', [LazadaDescriptionTemplateController::class, 'index'])->name('ext.lazada.description-templates.index');
        Route::post('/lazada/{store}/description-templates', [LazadaDescriptionTemplateController::class, 'store'])->name('ext.lazada.description-templates.store')->defaults('permission_tier', 'manage');
        Route::put('/lazada/{store}/description-templates/{template}', [LazadaDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.lazada.description-templates.update')->defaults('permission_tier', 'manage');
        Route::delete('/lazada/{store}/description-templates/{template}', [LazadaDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.lazada.description-templates.destroy')->defaults('permission_tier', 'manage');

        Route::get('/lazada/{store}/products/categories/children', [LazadaProductController::class, 'categoryChildren'])->name('ext.lazada.products.category_children');
        Route::get('/lazada/{store}/products/categories/path', [LazadaProductController::class, 'categoryPath'])->name('ext.lazada.products.category_path');
        Route::get('/lazada/{store}/products/categories/search', [LazadaProductController::class, 'categorySearch'])->name('ext.lazada.products.category_search');
        Route::get('/lazada/{store}/watermarks', [LazadaWatermarkTemplateController::class, 'index'])->name('ext.lazada.watermarks.index');
        Route::get('/lazada/{store}/watermarks/preview', [LazadaWatermarkTemplateController::class, 'preview'])->name('ext.lazada.watermarks.preview');
        Route::get('/lazada/{store}/watermarks/create', [LazadaWatermarkTemplateController::class, 'create'])->name('ext.lazada.watermarks.create')->defaults('permission_tier', 'manage');
        Route::post('/lazada/{store}/watermarks', [LazadaWatermarkTemplateController::class, 'store'])->name('ext.lazada.watermarks.store')->defaults('permission_tier', 'manage');
        Route::get('/lazada/{store}/watermarks/{template}/edit', [LazadaWatermarkTemplateController::class, 'edit'])->whereNumber('template')->name('ext.lazada.watermarks.edit')->defaults('permission_tier', 'manage');
        Route::put('/lazada/{store}/watermarks/{template}', [LazadaWatermarkTemplateController::class, 'update'])->whereNumber('template')->name('ext.lazada.watermarks.update')->defaults('permission_tier', 'manage');
        Route::delete('/lazada/{store}/watermarks/{template}', [LazadaWatermarkTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.lazada.watermarks.destroy')->defaults('permission_tier', 'manage');
        Route::get('/lazada/{store}/product-groups/{id}/edit', [LazadaProductGroupController::class, 'edit'])->whereNumber('id')->name('ext.lazada.product-groups.edit');
        Route::get('/lazada/{store}/product-groups/{id}/products', [LazadaProductGroupController::class, 'products'])->whereNumber('id')->name('ext.lazada.product-groups.products');
        Route::get('/lazada/{store}/product-groups/{id}/products/search', [LazadaProductGroupController::class, 'productSearch'])->whereNumber('id')->name('ext.lazada.product-groups.productSearch');
        Route::get('/lazada/{store}/product-groups/{id}/orphans', [LazadaProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.lazada.product-groups.orphans');

        Route::post('/lazada/{store}/product/payload-preview', [LazadaApiExplorerController::class, 'productPayloadPreview'])
            ->name('ext.lazada.product_payload_preview')
            ->defaults('permission_tier', 'view');
    });

    Route::group([], function () {
        Route::post('/lazada/{store}/save', [LazadaSettingsController::class, 'save'])->name('ext.lazada.save');
        Route::post('/lazada/{store}/toggle-mode', [LazadaSettingsController::class, 'toggleMode'])->name('ext.lazada.toggle_mode');
        Route::post('/lazada/{store}/token/create', [LazadaSettingsController::class, 'tokenCreate'])->name('ext.lazada.token_create');
        Route::post('/lazada/{store}/token/refresh', [LazadaSettingsController::class, 'tokenRefresh'])->name('ext.lazada.token_refresh');
        Route::post('/lazada/{store}/sandbox-token/save', [LazadaSettingsController::class, 'saveSandboxToken'])->name('ext.lazada.sandbox_token_save');

        Route::post('/lazada/{store}/order-status-map', [LazadaSettingsController::class, 'saveOrderStatusMap'])->name('ext.lazada.order_status_map');
        Route::post('/lazada/{store}/reverse-status-map', [LazadaSettingsController::class, 'saveReverseStatusMap'])->name('ext.lazada.reverse_status_map');

        Route::post('/lazada/{store}/api-log-mode', [LazadaSettingsController::class, 'setApiLogMode'])->name('ext.lazada.api_log_mode');
        Route::delete('/lazada/{store}/api-logs', [LazadaSettingsController::class, 'clearApiLogs'])->name('ext.lazada.clear_api_logs');
        Route::post('/lazada/{store}/purge-raw', [LazadaSettingsController::class, 'purgeRaw'])->name('ext.lazada.purge_raw');
        Route::post('/lazada/{store}/delete', [LazadaSettingsController::class, 'destroyStore'])->name('ext.lazada.stores.destroy');
        Route::post('/lazada/{store}/setup/{step}', [LazadaSettingsController::class, 'setupStep'])->whereIn('step', ['categories', 'brands'])->name('ext.lazada.setup_step');

        Route::post('/lazada/{store}/call', [LazadaApiExplorerController::class, 'callApi'])->name('ext.lazada.call_api');
        Route::post('/lazada/{store}/explorer/run', [LazadaApiExplorerController::class, 'explorerRun'])->name('ext.lazada.explorer_run');
        Route::post('/lazada/{store}/packs/run', [LazadaApiExplorerController::class, 'packsRun'])->name('ext.lazada.packs_run');

        Route::post('/lazada/{store}/category/tree', [LazadaApiExplorerController::class, 'categoryTree'])->name('ext.lazada.category_tree');
        Route::post('/lazada/{store}/category/attributes', [LazadaApiExplorerController::class, 'categoryAttributes'])->name('ext.lazada.category_attributes');
        Route::post('/lazada/{store}/category/brands', [LazadaApiExplorerController::class, 'brandsQuery'])->name('ext.lazada.brands_query');
        Route::post('/lazada/{store}/products/get', [LazadaApiExplorerController::class, 'productsGet'])->name('ext.lazada.products_get');
        Route::post('/lazada/{store}/product/create', [LazadaApiExplorerController::class, 'productCreate'])->name('ext.lazada.product_create');
        Route::post('/lazada/{store}/orders/get', [LazadaApiExplorerController::class, 'ordersGet'])->name('ext.lazada.orders_get');
        Route::post('/lazada/{store}/order/items/get', [LazadaApiExplorerController::class, 'orderItemsGet'])->name('ext.lazada.order_items_get');
        Route::post('/lazada/{store}/order/awb/pdf', [LazadaApiExplorerController::class, 'awbPdfGet'])->name('ext.lazada.awb_pdf_get');

        Route::get('/lazada/{store}/product-groups/create', [LazadaProductGroupController::class, 'create'])->name('ext.lazada.product-groups.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/lazada/{store}/product-groups', [LazadaProductGroupController::class, 'store'])->name('ext.lazada.product-groups.store');
        Route::put('/lazada/{store}/product-groups/{id}', [LazadaProductGroupController::class, 'update'])->whereNumber('id')->name('ext.lazada.product-groups.update');
        Route::delete('/lazada/{store}/product-groups/{id}', [LazadaProductGroupController::class, 'destroy'])->whereNumber('id')->name('ext.lazada.product-groups.destroy');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/add', [LazadaProductGroupController::class, 'addProduct'])->whereNumber('id')->whereNumber('productId')->name('ext.lazada.product-groups.addProduct');
        Route::post('/lazada/{store}/product-groups/{id}/products/check', [LazadaProductGroupController::class, 'checkAgainstLazada'])->whereNumber('id')->name('ext.lazada.product-groups.check');
        Route::post('/lazada/{store}/product-groups/{id}/products/push', [LazadaProductGroupController::class, 'push'])->whereNumber('id')->name('ext.lazada.product-groups.push');
        Route::post('/lazada/{store}/product-groups/{id}/send-runs', [LazadaProductGroupController::class, 'sendRunBegin'])->whereNumber('id')->name('ext.lazada.product-groups.send_run_begin');
        Route::post('/lazada/{store}/product-groups/{id}/send-runs/{run}/step', [LazadaProductGroupController::class, 'sendRunStep'])->whereNumber('id')->whereNumber('run')->name('ext.lazada.product-groups.send_run_step');
        Route::post('/lazada/{store}/product-groups/{id}/send-runs/{run}/stop', [LazadaProductGroupController::class, 'sendRunStop'])->whereNumber('id')->whereNumber('run')->name('ext.lazada.product-groups.send_run_stop');
        Route::post('/lazada/{store}/product-groups/{id}/products/update-product', [LazadaProductGroupController::class, 'updateProduct'])->whereNumber('id')->name('ext.lazada.product-groups.updateProduct');
        Route::post('/lazada/{store}/product-groups/{id}/products/push-prices', [LazadaProductGroupController::class, 'pushPrices'])->whereNumber('id')->name('ext.lazada.product-groups.pushPrices');
        Route::post('/lazada/{store}/product-groups/{id}/products/push-stock', [LazadaProductGroupController::class, 'pushStock'])->whereNumber('id')->name('ext.lazada.product-groups.pushStock');
        Route::post('/lazada/{store}/product-groups/{id}/products/mass-remove', [LazadaProductGroupController::class, 'massRemove'])->whereNumber('id')->name('ext.lazada.product-groups.massRemove');
        Route::post('/lazada/{store}/product-groups/move', [LazadaProductGroupController::class, 'moveProducts'])->name('ext.lazada.product-groups.move');
        Route::post('/lazada/{store}/product-groups/{id}/products/delete-from-lazada', [LazadaProductGroupController::class, 'deleteFromLazada'])->whereNumber('id')->name('ext.lazada.product-groups.deleteFromLazada');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/sync-id', [LazadaProductGroupController::class, 'syncId'])->whereNumber('id')->name('ext.lazada.product-groups.syncId');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/unlink', [LazadaProductGroupController::class, 'unlinkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.unlinkProduct');
        Route::post('/lazada/{store}/product-groups/{id}/products/{productId}/link', [LazadaProductGroupController::class, 'linkProduct'])->whereNumber('id')->name('ext.lazada.product-groups.linkProduct');
        Route::delete('/lazada/{store}/product-groups/{id}/products/{productId}', [LazadaProductGroupController::class, 'removeProduct'])->whereNumber('id')->name('ext.lazada.product-groups.removeProduct');
        Route::post('/lazada/{store}/product-groups/{id}/template/sync', [LazadaProductGroupController::class, 'syncTemplate'])->whereNumber('id')->name('ext.lazada.product-groups.template_sync');
        Route::post('/lazada/{store}/product-groups/refresh-categories', [LazadaProductGroupController::class, 'refreshCategories'])->name('ext.lazada.product-groups.refreshCategories');
        Route::post('/lazada/{store}/product-groups/fetch-attributes', [LazadaProductGroupController::class, 'fetchAttributesAjax'])->name('ext.lazada.product-groups.fetchAttributes');

        Route::get('/lazada/{store}/products/{productId}/edit', [LazadaProductController::class, 'edit'])->whereNumber('productId')->name('ext.lazada.products.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/lazada/{store}/products/{productId}', [LazadaProductController::class, 'update'])->whereNumber('productId')->name('ext.lazada.products.update');
        Route::post('/lazada/{store}/products/{productId}/catalog-change', [LazadaProductController::class, 'catalogChangeSave'])->whereNumber('productId')->name('ext.lazada.products.catalog_change_save');
        Route::post('/lazada/{store}/products/{productId}/catalog-change/ignore', [LazadaProductController::class, 'catalogChangeIgnore'])->whereNumber('productId')->name('ext.lazada.products.catalog_change_ignore');
        Route::post('/lazada/{store}/products/{productId}/attributes/fetch', [LazadaProductController::class, 'fetchAttributesAjax'])->whereNumber('productId')->name('ext.lazada.products.attributes_fetch');
        Route::post('/lazada/{store}/products/{productId}/variants', [LazadaProductController::class, 'saveVariants'])->whereNumber('productId')->name('ext.lazada.products.variants_save');
        Route::post('/lazada/{store}/products/{productId}/brands/sync', [LazadaProductController::class, 'syncBrands'])->whereNumber('productId')->name('ext.lazada.products.brands_sync');
        Route::post('/lazada/{store}/products/{productId}/sync/quantity', [LazadaProductController::class, 'syncQuantity'])->whereNumber('productId')->name('ext.lazada.products.sync_quantity');
        Route::post('/lazada/{store}/products/{productId}/sync/price', [LazadaProductController::class, 'syncPrice'])->whereNumber('productId')->name('ext.lazada.products.sync_price');
        Route::post('/lazada/{store}/products/{productId}/upload', [LazadaProductController::class, 'uploadToLazada'])->whereNumber('productId')->name('ext.lazada.products.upload');
        Route::post('/lazada/{store}/products/{productId}/delete/lazada', [LazadaProductController::class, 'deleteFromLazada'])->whereNumber('productId')->name('ext.lazada.products.delete_lazada');
        Route::post('/lazada/{store}/products/{productId}/unlink', [LazadaProductController::class, 'unlink'])->whereNumber('productId')->name('ext.lazada.products.unlink');
        Route::post('/lazada/{store}/products/{productId}/sync/lazada-id', [LazadaProductController::class, 'syncLazadaId'])->whereNumber('productId')->name('ext.lazada.products.sync_lazada_id');

        Route::post('/lazada/{store}/products/import-one', [LazadaProductController::class, 'importOne'])->name('ext.lazada.products.import_one');
        Route::post('/lazada/{store}/products/import-selected', [LazadaProductController::class, 'importSelected'])->name('ext.lazada.products.import_selected');
        Route::post('/lazada/{store}/products/link-item', [LazadaProductController::class, 'linkItem'])->name('ext.lazada.products.link_item');
        Route::post('/lazada/{store}/products/add-to-store', [LazadaProductController::class, 'addToStoreBulk'])->name('ext.lazada.products.add_to_store_bulk');
        Route::post('/lazada/{store}/products/{productId}/add-to-store', [LazadaProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.lazada.products.add_to_store');
        Route::get('/lazada/{store}/products/catalogue-search', [LazadaProductController::class, 'catalogueSearch'])
            ->name('ext.lazada.products.catalogue_search')
            ->defaults('permission_tier', 'view');
        Route::post('/lazada/{store}/products/{productId}/remove-from-store', [LazadaProductController::class, 'removeFromStore'])->whereNumber('productId')->name('ext.lazada.products.remove_from_store');
        Route::post('/lazada/{store}/listings/{productId}/toggle', [LazadaProductController::class, 'toggleListing'])->whereNumber('productId')->name('ext.lazada.listings.toggle');
        Route::post('/lazada/{store}/products/bulk/push', [LazadaProductController::class, 'bulkPush'])->name('ext.lazada.products.bulk_push');
        Route::post('/lazada/{store}/products/bulk/toggle', [LazadaProductController::class, 'bulkToggle'])->name('ext.lazada.products.bulk_toggle');
        Route::post('/lazada/{store}/products/refresh-status', [LazadaProductController::class, 'refreshStatus'])->name('ext.lazada.products.refresh_status');

        Route::post('/lazada/{store}/vouchers', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'store'])->name('ext.lazada.vouchers.store');
        Route::post('/lazada/{store}/vouchers/{voucherId}/deactivate', [\Extensions\lazada\Controllers\LazadaVoucherController::class, 'deactivate'])->where('voucherId', '[A-Za-z0-9_-]+')->name('ext.lazada.vouchers.deactivate');
        Route::post('/lazada/{store}/products/check', [LazadaProductController::class, 'checkAgainstLazada'])->name('ext.lazada.products.check');
        Route::post('/lazada/{store}/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('ext.lazada.products.bulk_sync_quantity');
        Route::post('/lazada/{store}/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('ext.lazada.products.bulk_sync_price');
        Route::post('/lazada/{store}/products/bulk/sync/lazada-id', [LazadaProductController::class, 'bulkSyncLazadaId'])->name('ext.lazada.products.bulk_sync_lazada_id');
        Route::post('/lazada/{store}/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('ext.lazada.products.bulk_delete');
        Route::post('/lazada/{store}/products/bulk/remove-from-store', [LazadaProductController::class, 'bulkRemoveFromStore'])->name('ext.lazada.products.bulk_remove_from_store');


        Route::post('/lazada/{store}/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('ext.lazada.brands_sync');
        Route::post('/lazada/{store}/brands/fetch', [LazadaBrandController::class, 'fetch'])->name('ext.lazada.brands.fetch');
        Route::post('/lazada/{store}/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('ext.lazada.categories.fetch');
        Route::post('/lazada/{store}/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('ext.lazada.categories.attributes.fetch');
    });

    Route::group([], function () {
        Route::get('/lazada/{store}/orders', [LazadaOrderController::class, 'index'])->name('ext.lazada.orders.index');
        Route::get('/lazada/{store}/orders/returns', [LazadaOrderController::class, 'returns'])->name('ext.lazada.orders.returns');
        Route::get('/lazada/{store}/orders/{orderId}', [LazadaOrderController::class, 'show'])->name('ext.lazada.orders.show');
        Route::get('/lazada/{store}/orders/{orderId}/awb', [LazadaOrderController::class, 'awbPdf'])->name('ext.lazada.orders.awb');
        Route::post('/lazada/{store}/orders/bulk-awb', [LazadaOrderController::class, 'bulkAwb'])
            ->name('ext.lazada.orders.bulk_awb')
            ->defaults('permission_tier', 'view');
        Route::get('/lazada/{store}/orders/{orderId}/packing-list', [LazadaOrderController::class, 'packingList'])->name('ext.lazada.orders.packing_list');
        Route::get('/lazada/{store}/orders/{orderId}/pick-list', [LazadaOrderController::class, 'pickList'])->name('ext.lazada.orders.pick_list');
        Route::get('/lazada/{store}/orders/{orderId}/logistics-trace', [LazadaOrderController::class, 'logisticsTrace'])->name('ext.lazada.orders.logistics_trace');
        Route::get('/lazada/{store}/orders/{orderId}/cancel-reasons', [LazadaOrderController::class, 'cancelReasons'])->name('ext.lazada.orders.cancel_reasons');
    });

    Route::group([], function () {
        Route::post('/lazada/{store}/orders/returns/fetch', [LazadaOrderController::class, 'fetchReturns'])->name('ext.lazada.orders.fetch_returns');
        Route::post('/lazada/{store}/orders/fetch', [LazadaOrderController::class, 'fetch'])->name('ext.lazada.orders.fetch');
        Route::post('/lazada/{store}/orders/fetch-runs', [LazadaOrderController::class, 'fetchRunBegin'])->name('ext.lazada.orders.fetch_run_begin');
        Route::post('/lazada/{store}/orders/fetch-runs/{run}/step', [LazadaOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.lazada.orders.fetch_run_step');
        Route::post('/lazada/{store}/orders/fetch-runs/{run}/stop', [LazadaOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.lazada.orders.fetch_run_stop');
        Route::get('/lazada/{store}/orders/fetch-runs/{run}', [LazadaOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.lazada.orders.fetch_run_state');
        Route::post('/lazada/{store}/orders/update-statuses', [LazadaOrderController::class, 'updateStatuses'])->name('ext.lazada.orders.update_statuses');
        Route::post('/lazada/{store}/orders/reset', [LazadaOrderController::class, 'reset'])->name('ext.lazada.orders.reset');
        Route::post('/lazada/{store}/orders/{orderId}/pack', [LazadaOrderController::class, 'pack'])->middleware(['packing.check:lazada', 'booking.lock:lazada'])->name('ext.lazada.orders.pack');
        Route::post('/lazada/{store}/orders/{orderId}/pack-print', [LazadaOrderController::class, 'packAndPrint'])->middleware(['packing.check:lazada', 'booking.lock:lazada'])->name('ext.lazada.orders.pack_print');
        Route::post('/lazada/{store}/orders/bulk-pack-print', [LazadaOrderController::class, 'bulkPackPrint'])->middleware(['packing.check:lazada', 'booking.lock:lazada'])->name('ext.lazada.orders.bulk_pack_print');
        Route::post('/lazada/{store}/orders/{orderId}/recreate-package', [LazadaOrderController::class, 'recreatePackage'])->middleware('booking.lock:lazada')->name('ext.lazada.orders.recreate_package');
        Route::post('/lazada/{store}/orders/{orderId}/rts', [LazadaOrderController::class, 'rts'])->middleware('booking.lock:lazada')->name('ext.lazada.orders.rts');
        Route::post('/lazada/{store}/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrintPost'])->middleware('booking.lock:lazada')->name('ext.lazada.orders.ship_print_post');
        Route::post('/lazada/{store}/orders/{orderId}/cancel', [LazadaOrderController::class, 'cancel'])->name('ext.lazada.orders.cancel');
    });

});

Route::middleware(['auth', \Extensions\lazada\Http\ResolveLazadaStore::class])->prefix('channels')->group(function () {
    Route::get('/lazada/{store?}', [\Extensions\lazada\Controllers\LazadaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.lazada.dashboard');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/lazada/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\lazada\Models\LazadaSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/lazada/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.lazada.legacy_storeless');
});
