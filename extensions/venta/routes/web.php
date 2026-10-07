<?php

use Extensions\venta\Controllers\VentaDescriptionTemplateController;
use Extensions\venta\Controllers\VentaWatermarkTemplateController;
use Extensions\venta\Controllers\VentaListingController;
use Extensions\venta\Controllers\VentaSettingsController;
use Extensions\venta\Controllers\VentaProductGroupController;
use Extensions\venta\Controllers\VentaOrderController;
use Extensions\venta\Controllers\VentaReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store}/settings', [VentaSettingsController::class, 'showSettings'])->name('ext.venta.settings.show');

    Route::get('/venta/settings', [VentaSettingsController::class, 'index'])->name('ext.venta.index');

    Route::get('/venta/{store}/product-groups', [VentaProductGroupController::class, 'index'])->name('ext.venta.product-groups.index');
    
    Route::get('/venta/{store}/description-templates', [VentaDescriptionTemplateController::class, 'index'])->name('ext.venta.description-templates.index');
    Route::post('/venta/{store}/description-templates', [VentaDescriptionTemplateController::class, 'store'])->name('ext.venta.description-templates.store')->defaults('permission_tier', 'manage');
    Route::put('/venta/{store}/description-templates/{template}', [VentaDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.venta.description-templates.update')->defaults('permission_tier', 'manage');
    Route::delete('/venta/{store}/description-templates/{template}', [VentaDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.venta.description-templates.destroy')->defaults('permission_tier', 'manage');

    Route::get('/venta/{store}/listings/categories/children', [VentaListingController::class, 'categoryChildren'])->name('ext.venta.listings.category_children');
    Route::get('/venta/{store}/listings/categories/path', [VentaListingController::class, 'categoryPath'])->name('ext.venta.listings.category_path');
    Route::get('/venta/{store}/listings/categories/search', [VentaListingController::class, 'categorySearch'])->name('ext.venta.listings.category_search');
    Route::get('/venta/{store}/watermarks', [VentaWatermarkTemplateController::class, 'index'])->name('ext.venta.watermarks.index');
    Route::get('/venta/{store}/watermarks/preview', [VentaWatermarkTemplateController::class, 'preview'])->name('ext.venta.watermarks.preview');
    Route::get('/venta/{store}/watermarks/create', [VentaWatermarkTemplateController::class, 'create'])->name('ext.venta.watermarks.create')->defaults('permission_tier', 'manage');
    Route::post('/venta/{store}/watermarks', [VentaWatermarkTemplateController::class, 'store'])->name('ext.venta.watermarks.store')->defaults('permission_tier', 'manage');
    Route::get('/venta/{store}/watermarks/{template}/edit', [VentaWatermarkTemplateController::class, 'edit'])->whereNumber('template')->name('ext.venta.watermarks.edit')->defaults('permission_tier', 'manage');
    Route::put('/venta/{store}/watermarks/{template}', [VentaWatermarkTemplateController::class, 'update'])->whereNumber('template')->name('ext.venta.watermarks.update')->defaults('permission_tier', 'manage');
    Route::delete('/venta/{store}/watermarks/{template}', [VentaWatermarkTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.venta.watermarks.destroy')->defaults('permission_tier', 'manage');
    Route::get('/venta/{store}/product-groups/{group}/edit', [VentaProductGroupController::class, 'edit'])->name('ext.venta.product-groups.edit');
    Route::get('/venta/{store}/product-groups/{group}/products', [VentaProductGroupController::class, 'products'])->name('ext.venta.product-groups.products');
    Route::get('/venta/{store}/product-groups/{group}/orphans', [VentaProductGroupController::class, 'orphans'])->name('ext.venta.product-groups.orphans');

    Route::get('/venta/{store}/product-groups/search-products', [VentaProductGroupController::class, 'searchProducts'])->name('ext.venta.product-groups.searchProducts');

    Route::get('/venta/{store}/listings', [VentaListingController::class, 'index'])->name('ext.venta.listings.index');
    Route::post('/venta/{store}/listings/check', [VentaListingController::class, 'checkAgainstVenta'])->name('ext.venta.listings.check');
    Route::post('/venta/{store}/listings/{productId}/push-stock', [VentaListingController::class, 'pushStock'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.listings.push_stock');
    Route::post('/venta/{store}/listings/{productId}/push-price', [VentaListingController::class, 'pushPrice'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.listings.push_price');
    Route::post('/venta/{store}/listings/bulk/push', [VentaListingController::class, 'bulkPush'])->whereNumber('store')->name('ext.venta.listings.bulk_push');
    Route::post('/venta/{store}/listings/bulk/push-stock', [VentaListingController::class, 'bulkPushStock'])->whereNumber('store')->name('ext.venta.listings.bulk_push_stock');
    Route::post('/venta/{store}/listings/bulk/push-prices', [VentaListingController::class, 'bulkPushPrices'])->whereNumber('store')->name('ext.venta.listings.bulk_push_prices');
    Route::post('/venta/{store}/products/{productId}/unlink', [VentaListingController::class, 'unlink'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.unlink');
    Route::post('/venta/{store}/products/{productId}/delete', [VentaListingController::class, 'deleteFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.delete');
    Route::post('/venta/{store}/products/bulk/delete', [VentaListingController::class, 'bulkDeleteFromStore'])->whereNumber('store')->name('ext.venta.products.bulk_delete');
    Route::post('/venta/{store}/products/bulk/remove-from-store', [VentaListingController::class, 'bulkRemoveFromStore'])->whereNumber('store')->name('ext.venta.products.bulk_remove_from_store');
    Route::get('/venta/{store}/products/import', [VentaListingController::class, 'importPage'])->name('ext.venta.products.import');
    Route::get('/venta/{store}/products/search-catalog', [VentaListingController::class, 'searchCatalog'])->name('ext.venta.products.search_catalog');
    Route::post('/venta/{store}/products/import-one', [VentaListingController::class, 'importOne'])->whereNumber('store')->name('ext.venta.products.import_one');
    Route::post('/venta/{store}/products/import-selected', [VentaListingController::class, 'importSelected'])->whereNumber('store')->name('ext.venta.products.import_selected');
    Route::post('/venta/{store}/products/link-item', [VentaListingController::class, 'linkItem'])->whereNumber('store')->name('ext.venta.products.link_item');
    Route::get('/venta/{store}/products/catalogue-search', [VentaListingController::class, 'catalogueSearch'])->whereNumber('store')->name('ext.venta.products.catalogue_search');
    Route::post('/venta/{store}/products/add-to-store/{productId}', [VentaListingController::class, 'addToStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.add_to_store');
    Route::post('/venta/{store}/products/add-to-store', [VentaListingController::class, 'addToStoreBulk'])->whereNumber('store')->name('ext.venta.products.add_to_store_bulk');
    Route::post('/venta/{store}/products/remove-from-store/{productId}', [VentaListingController::class, 'removeFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.remove_from_store');
    Route::get('/venta/{store}/listings/{productId}', [VentaListingController::class, 'edit'])
        ->whereNumber('productId')->name('ext.venta.listings.edit');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/stores', [VentaSettingsController::class, 'createStore'])->name('ext.venta.stores.store');

    Route::post('/venta/save', [VentaSettingsController::class, 'save'])->name('ext.venta.save');
    Route::delete('/venta/{id}', [VentaSettingsController::class, 'destroy'])->name('ext.venta.destroy');
    Route::post('/venta/{store}/delete', [VentaSettingsController::class, 'destroyStore'])->whereNumber('store')->name('ext.venta.stores.destroy');
    Route::post('/venta/{store}/setup/{step}', [VentaSettingsController::class, 'setupStep'])->whereNumber('store')->whereIn('step', ['categories', 'brands', 'statuses'])->name('ext.venta.setup_step');
    Route::post('/venta/test', [VentaSettingsController::class, 'testConnection'])->name('ext.venta.test');
    Route::post('/venta/save-status-map', [VentaSettingsController::class, 'saveOrderStatusMap'])->name('ext.venta.save_status_map');
    Route::post('/venta/{id}/api-log-mode', [VentaSettingsController::class, 'setApiLogMode'])->name('ext.venta.api_log_mode');
    Route::delete('/venta/{id}/api-logs', [VentaSettingsController::class, 'clearApiLogs'])->name('ext.venta.clear_api_logs');
    Route::post('/venta/{id}/sync-log-level', [VentaSettingsController::class, 'setSyncLogLevel'])->name('ext.venta.set_sync_log_level');
    Route::delete('/venta/{id}/sync-logs', [VentaSettingsController::class, 'clearSyncLogs'])->name('ext.venta.clear_sync_logs');
    Route::post('/venta/fetch-statuses', [VentaSettingsController::class, 'fetchVentaStatuses'])->name('ext.venta.fetch_statuses');

    Route::post('/venta/fetch-categories', [VentaSettingsController::class, 'fetchCategories'])->name('ext.venta.fetch_categories');
    Route::post('/venta/fetch-brands', [VentaSettingsController::class, 'fetchBrands'])->name('ext.venta.fetch_brands');

    Route::get('/venta/{store}/product-groups/create', [VentaProductGroupController::class, 'create'])->name('ext.venta.product-groups.create')
        // A form page is a manage surface even though it is a GET; do not demote it to the view tier.
        ->defaults('permission_tier', 'manage');
    Route::post('/venta/{store}/product-groups', [VentaProductGroupController::class, 'store'])->name('ext.venta.product-groups.store');
    Route::put('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'update'])->name('ext.venta.product-groups.update');
    Route::delete('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'destroy'])->name('ext.venta.product-groups.destroy');

    Route::post('/venta/{store}/product-groups/{group}/push', [VentaProductGroupController::class, 'pushProducts'])->name('ext.venta.product-groups.push');
    Route::post('/venta/{store}/product-groups/{group}/send-runs', [VentaProductGroupController::class, 'sendRunBegin'])->whereNumber('group')->name('ext.venta.product-groups.send_run_begin');
    Route::post('/venta/{store}/product-groups/{group}/send-runs/{run}/step', [VentaProductGroupController::class, 'sendRunStep'])->whereNumber('group')->whereNumber('run')->name('ext.venta.product-groups.send_run_step');
    Route::post('/venta/{store}/product-groups/{group}/send-runs/{run}/stop', [VentaProductGroupController::class, 'sendRunStop'])->whereNumber('group')->whereNumber('run')->name('ext.venta.product-groups.send_run_stop');
    Route::post('/venta/{store}/product-groups/{group}/push-stock', [VentaProductGroupController::class, 'pushStock'])->name('ext.venta.product-groups.push-stock');
    Route::post('/venta/{store}/product-groups/{group}/push-prices', [VentaProductGroupController::class, 'pushPrices'])->name('ext.venta.product-groups.push-prices');
    Route::post('/venta/{store}/product-groups/{group}/add-products', [VentaProductGroupController::class, 'addProducts'])->name('ext.venta.product-groups.addProducts');
    Route::post('/venta/{store}/product-groups/{group}/mass-remove', [VentaProductGroupController::class, 'massRemove'])->name('ext.venta.product-groups.mass-remove');
    Route::post('/venta/{store}/product-groups/move', [VentaProductGroupController::class, 'moveProducts'])->name('ext.venta.product-groups.move');
    Route::post('/venta/{store}/product-groups/{group}/check', [VentaProductGroupController::class, 'checkAgainstVenta'])->name('ext.venta.product-groups.check');
    Route::delete('/venta/{store}/product-groups/{group}/products/{product}', [VentaProductGroupController::class, 'removeProduct'])->name('ext.venta.product-groups.removeProduct');

    Route::post('/venta/{store}/product-groups/{group}/products/{product}/sync-id', [VentaProductGroupController::class, 'syncId'])->name('ext.venta.product-groups.sync-id');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/unlink', [VentaProductGroupController::class, 'unlinkProduct'])->name('ext.venta.product-groups.unlink');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.deleteFromVenta');
    Route::post('/venta/{store}/product-groups/{group}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.bulk-delete-from-venta');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/link', [VentaProductGroupController::class, 'linkProduct'])->name('ext.venta.product-groups.link');

    Route::post('/venta/{store}/listings/refresh-status', [VentaListingController::class, 'refreshStatus'])->name('ext.venta.listings.refresh_status');
    Route::post('/venta/{store}/listings/bulk-toggle', [VentaListingController::class, 'bulkToggle'])->name('ext.venta.listings.bulk_toggle');
    Route::put('/venta/{store}/listings/{productId}', [VentaListingController::class, 'update'])->whereNumber('productId')->name('ext.venta.listings.update');
    Route::post('/venta/{store}/listings/{productId}/catalog-change', [VentaListingController::class, 'catalogChangeSave'])->whereNumber('productId')->name('ext.venta.listings.catalog_change_save');
    Route::post('/venta/{store}/listings/{productId}/catalog-change/ignore', [VentaListingController::class, 'catalogChangeIgnore'])->whereNumber('productId')->name('ext.venta.listings.catalog_change_ignore');
    Route::post('/venta/{store}/listings/{productId}/push', [VentaListingController::class, 'push'])->whereNumber('productId')->name('ext.venta.listings.push');
    Route::post('/venta/{store}/listings/{productId}/toggle', [VentaListingController::class, 'toggle'])->whereNumber('productId')->name('ext.venta.listings.toggle');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store}/orders', [VentaOrderController::class, 'index'])->name('ext.venta.orders.index');

    Route::get('/venta/{store}/orders/{order}/awb', [VentaOrderController::class, 'awb'])->whereNumber('order')->name('ext.venta.orders.awb');
    Route::get('/venta/{store}/orders/{order}/tracking', [VentaOrderController::class, 'tracking'])->whereNumber('order')->name('ext.venta.orders.tracking');
    Route::get('/venta/{store}/orders/{order}/serviceability', [VentaOrderController::class, 'serviceability'])->whereNumber('order')->name('ext.venta.orders.serviceability');
    Route::get('/venta/{store}/orders/{order}/pickup-slots', [VentaOrderController::class, 'pickupSlots'])->whereNumber('order')->name('ext.venta.orders.pickup_slots');
    Route::get('/venta/{store}/orders/shipping-couriers', [VentaOrderController::class, 'shippingCouriers'])->name('ext.venta.orders.shipping_couriers');
    Route::get('/venta/{store}/orders/pickup-addresses', [VentaOrderController::class, 'pickupAddresses'])->name('ext.venta.orders.pickup_addresses');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/{store}/orders/fetch', [VentaOrderController::class, 'fetch'])->name('ext.venta.orders.fetch');
    Route::post('/venta/{store}/orders/fetch-runs', [VentaOrderController::class, 'fetchRunBegin'])->name('ext.venta.orders.fetch_run_begin');
    Route::post('/venta/{store}/orders/fetch-runs/{run}/step', [VentaOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.venta.orders.fetch_run_step');
    Route::post('/venta/{store}/orders/fetch-runs/{run}/stop', [VentaOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.venta.orders.fetch_run_stop');
    Route::get('/venta/{store}/orders/fetch-runs/{run}', [VentaOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.venta.orders.fetch_run_state');

    Route::post('/venta/{store}/orders/{order}/estimate', [VentaOrderController::class, 'estimate'])->whereNumber('order')->name('ext.venta.orders.estimate');
    Route::post('/venta/{store}/orders/{order}/book', [VentaOrderController::class, 'book'])->whereNumber('order')->middleware(['packing.check:venta', 'booking.lock:venta'])->name('ext.venta.orders.book');
    Route::post('/venta/{store}/orders/{order}/cancel-booking', [VentaOrderController::class, 'cancelBooking'])->whereNumber('order')->middleware('booking.lock:venta')->name('ext.venta.orders.cancel_booking');
    Route::post('/venta/{store}/orders/{order}/book-manual', [VentaOrderController::class, 'bookManual'])->whereNumber('order')->middleware(['packing.check:venta', 'booking.lock:venta'])->name('ext.venta.orders.book_manual');
    Route::post('/venta/{store}/orders/{order}/clear-manual', [VentaOrderController::class, 'clearManual'])->whereNumber('order')->name('ext.venta.orders.clear_manual');
    Route::delete('/venta/{store}/orders/{order}', [VentaOrderController::class, 'destroy'])->name('ext.venta.orders.destroy');
    Route::post('/venta/{store}/orders/bulk-delete', [VentaOrderController::class, 'bulkDelete'])->name('ext.venta.orders.bulk_delete');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/reviews/{id}/push', [VentaReviewController::class, 'push'])->name('ext.venta.reviews.push')->whereNumber('id');
    Route::post('/venta/reviews/push-all', [VentaReviewController::class, 'pushAll'])->name('ext.venta.reviews.push_all');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store?}', [\Extensions\venta\Controllers\VentaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.venta.dashboard');
});
