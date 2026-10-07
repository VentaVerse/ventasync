<?php

use Extensions\ventacart\Controllers\VentaCartDescriptionTemplateController;
use Extensions\ventacart\Controllers\VentaCartWatermarkTemplateController;
use Extensions\ventacart\Controllers\VentaCartListingController;
use Extensions\ventacart\Controllers\VentaCartSettingsController;
use Extensions\ventacart\Controllers\VentaCartProductGroupController;
use Extensions\ventacart\Controllers\VentaCartOrderController;
use Extensions\ventacart\Controllers\VentaCartReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/ventacart/{store}/settings', [VentaCartSettingsController::class, 'showSettings'])->name('ext.ventacart.settings.show');

    Route::get('/ventacart/settings', [VentaCartSettingsController::class, 'index'])->name('ext.ventacart.index');

    Route::get('/ventacart/{store}/product-groups', [VentaCartProductGroupController::class, 'index'])->name('ext.ventacart.product-groups.index');
    
    Route::get('/ventacart/{store}/description-templates', [VentaCartDescriptionTemplateController::class, 'index'])->name('ext.ventacart.description-templates.index');
    Route::post('/ventacart/{store}/description-templates', [VentaCartDescriptionTemplateController::class, 'store'])->name('ext.ventacart.description-templates.store')->defaults('permission_tier', 'manage');
    Route::put('/ventacart/{store}/description-templates/{template}', [VentaCartDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.ventacart.description-templates.update')->defaults('permission_tier', 'manage');
    Route::delete('/ventacart/{store}/description-templates/{template}', [VentaCartDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.ventacart.description-templates.destroy')->defaults('permission_tier', 'manage');

    Route::get('/ventacart/{store}/listings/categories/children', [VentaCartListingController::class, 'categoryChildren'])->name('ext.ventacart.listings.category_children');
    Route::get('/ventacart/{store}/listings/categories/path', [VentaCartListingController::class, 'categoryPath'])->name('ext.ventacart.listings.category_path');
    Route::get('/ventacart/{store}/listings/categories/search', [VentaCartListingController::class, 'categorySearch'])->name('ext.ventacart.listings.category_search');
    Route::get('/ventacart/{store}/watermarks', [VentaCartWatermarkTemplateController::class, 'index'])->name('ext.ventacart.watermarks.index');
    Route::get('/ventacart/{store}/watermarks/preview', [VentaCartWatermarkTemplateController::class, 'preview'])->name('ext.ventacart.watermarks.preview');
    Route::get('/ventacart/{store}/watermarks/create', [VentaCartWatermarkTemplateController::class, 'create'])->name('ext.ventacart.watermarks.create')->defaults('permission_tier', 'manage');
    Route::post('/ventacart/{store}/watermarks', [VentaCartWatermarkTemplateController::class, 'store'])->name('ext.ventacart.watermarks.store')->defaults('permission_tier', 'manage');
    Route::get('/ventacart/{store}/watermarks/{template}/edit', [VentaCartWatermarkTemplateController::class, 'edit'])->whereNumber('template')->name('ext.ventacart.watermarks.edit')->defaults('permission_tier', 'manage');
    Route::put('/ventacart/{store}/watermarks/{template}', [VentaCartWatermarkTemplateController::class, 'update'])->whereNumber('template')->name('ext.ventacart.watermarks.update')->defaults('permission_tier', 'manage');
    Route::delete('/ventacart/{store}/watermarks/{template}', [VentaCartWatermarkTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.ventacart.watermarks.destroy')->defaults('permission_tier', 'manage');
    Route::get('/ventacart/{store}/product-groups/{group}/edit', [VentaCartProductGroupController::class, 'edit'])->name('ext.ventacart.product-groups.edit');
    Route::get('/ventacart/{store}/product-groups/{group}/products', [VentaCartProductGroupController::class, 'products'])->name('ext.ventacart.product-groups.products');
    Route::get('/ventacart/{store}/product-groups/{group}/orphans', [VentaCartProductGroupController::class, 'orphans'])->name('ext.ventacart.product-groups.orphans');

    Route::get('/ventacart/{store}/product-groups/search-products', [VentaCartProductGroupController::class, 'searchProducts'])->name('ext.ventacart.product-groups.searchProducts');

    Route::get('/ventacart/{store}/listings', [VentaCartListingController::class, 'index'])->name('ext.ventacart.listings.index');
    Route::post('/ventacart/{store}/listings/check', [VentaCartListingController::class, 'checkAgainstVenta'])->name('ext.ventacart.listings.check');
    Route::post('/ventacart/{store}/listings/{productId}/push-stock', [VentaCartListingController::class, 'pushStock'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.listings.push_stock');
    Route::post('/ventacart/{store}/listings/{productId}/push-price', [VentaCartListingController::class, 'pushPrice'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.listings.push_price');
    Route::post('/ventacart/{store}/listings/bulk/push', [VentaCartListingController::class, 'bulkPush'])->whereNumber('store')->name('ext.ventacart.listings.bulk_push');
    Route::post('/ventacart/{store}/listings/bulk/push-stock', [VentaCartListingController::class, 'bulkPushStock'])->whereNumber('store')->name('ext.ventacart.listings.bulk_push_stock');
    Route::post('/ventacart/{store}/listings/bulk/push-prices', [VentaCartListingController::class, 'bulkPushPrices'])->whereNumber('store')->name('ext.ventacart.listings.bulk_push_prices');
    Route::post('/ventacart/{store}/products/{productId}/unlink', [VentaCartListingController::class, 'unlink'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.products.unlink');
    Route::post('/ventacart/{store}/products/{productId}/delete', [VentaCartListingController::class, 'deleteFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.products.delete');
    Route::post('/ventacart/{store}/products/bulk/delete', [VentaCartListingController::class, 'bulkDeleteFromStore'])->whereNumber('store')->name('ext.ventacart.products.bulk_delete');
    Route::post('/ventacart/{store}/products/bulk/remove-from-store', [VentaCartListingController::class, 'bulkRemoveFromStore'])->whereNumber('store')->name('ext.ventacart.products.bulk_remove_from_store');
    Route::get('/ventacart/{store}/products/import', [VentaCartListingController::class, 'importPage'])->name('ext.ventacart.products.import');
    Route::get('/ventacart/{store}/products/search-catalog', [VentaCartListingController::class, 'searchCatalog'])->name('ext.ventacart.products.search_catalog');
    Route::post('/ventacart/{store}/products/import-one', [VentaCartListingController::class, 'importOne'])->whereNumber('store')->name('ext.ventacart.products.import_one');
    Route::post('/ventacart/{store}/products/import-selected', [VentaCartListingController::class, 'importSelected'])->whereNumber('store')->name('ext.ventacart.products.import_selected');
    Route::post('/ventacart/{store}/products/link-item', [VentaCartListingController::class, 'linkItem'])->whereNumber('store')->name('ext.ventacart.products.link_item');
    Route::get('/ventacart/{store}/products/catalogue-search', [VentaCartListingController::class, 'catalogueSearch'])->whereNumber('store')->name('ext.ventacart.products.catalogue_search');
    Route::post('/ventacart/{store}/products/add-to-store/{productId}', [VentaCartListingController::class, 'addToStore'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.products.add_to_store');
    Route::post('/ventacart/{store}/products/add-to-store', [VentaCartListingController::class, 'addToStoreBulk'])->whereNumber('store')->name('ext.ventacart.products.add_to_store_bulk');
    Route::post('/ventacart/{store}/products/remove-from-store/{productId}', [VentaCartListingController::class, 'removeFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.ventacart.products.remove_from_store');
    Route::get('/ventacart/{store}/listings/{productId}', [VentaCartListingController::class, 'edit'])
        ->whereNumber('productId')->name('ext.ventacart.listings.edit');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/ventacart/stores', [VentaCartSettingsController::class, 'createStore'])->name('ext.ventacart.stores.store');

    Route::post('/ventacart/save', [VentaCartSettingsController::class, 'save'])->name('ext.ventacart.save');
    Route::delete('/ventacart/{id}', [VentaCartSettingsController::class, 'destroy'])->name('ext.ventacart.destroy');
    Route::post('/ventacart/{store}/delete', [VentaCartSettingsController::class, 'destroyStore'])->whereNumber('store')->name('ext.ventacart.stores.destroy');
    Route::post('/ventacart/{store}/setup/{step}', [VentaCartSettingsController::class, 'setupStep'])->whereNumber('store')->whereIn('step', ['categories', 'brands', 'statuses'])->name('ext.ventacart.setup_step');
    Route::post('/ventacart/test', [VentaCartSettingsController::class, 'testConnection'])->name('ext.ventacart.test');
    Route::post('/ventacart/save-status-map', [VentaCartSettingsController::class, 'saveOrderStatusMap'])->name('ext.ventacart.save_status_map');
    Route::post('/ventacart/{id}/api-log-mode', [VentaCartSettingsController::class, 'setApiLogMode'])->name('ext.ventacart.api_log_mode');
    Route::delete('/ventacart/{id}/api-logs', [VentaCartSettingsController::class, 'clearApiLogs'])->name('ext.ventacart.clear_api_logs');
    Route::post('/ventacart/{id}/sync-log-level', [VentaCartSettingsController::class, 'setSyncLogLevel'])->name('ext.ventacart.set_sync_log_level');
    Route::delete('/ventacart/{id}/sync-logs', [VentaCartSettingsController::class, 'clearSyncLogs'])->name('ext.ventacart.clear_sync_logs');
    Route::post('/ventacart/fetch-statuses', [VentaCartSettingsController::class, 'fetchVentaStatuses'])->name('ext.ventacart.fetch_statuses');

    Route::post('/ventacart/fetch-categories', [VentaCartSettingsController::class, 'fetchCategories'])->name('ext.ventacart.fetch_categories');
    Route::post('/ventacart/fetch-brands', [VentaCartSettingsController::class, 'fetchBrands'])->name('ext.ventacart.fetch_brands');

    Route::get('/ventacart/{store}/product-groups/create', [VentaCartProductGroupController::class, 'create'])->name('ext.ventacart.product-groups.create')
        // A form page is a manage surface even though it is a GET; do not demote it to the view tier.
        ->defaults('permission_tier', 'manage');
    Route::post('/ventacart/{store}/product-groups', [VentaCartProductGroupController::class, 'store'])->name('ext.ventacart.product-groups.store');
    Route::put('/ventacart/{store}/product-groups/{group}', [VentaCartProductGroupController::class, 'update'])->name('ext.ventacart.product-groups.update');
    Route::delete('/ventacart/{store}/product-groups/{group}', [VentaCartProductGroupController::class, 'destroy'])->name('ext.ventacart.product-groups.destroy');

    Route::post('/ventacart/{store}/product-groups/{group}/push', [VentaCartProductGroupController::class, 'pushProducts'])->name('ext.ventacart.product-groups.push');
    Route::post('/ventacart/{store}/product-groups/{group}/send-runs', [VentaCartProductGroupController::class, 'sendRunBegin'])->whereNumber('group')->name('ext.ventacart.product-groups.send_run_begin');
    Route::post('/ventacart/{store}/product-groups/{group}/send-runs/{run}/step', [VentaCartProductGroupController::class, 'sendRunStep'])->whereNumber('group')->whereNumber('run')->name('ext.ventacart.product-groups.send_run_step');
    Route::post('/ventacart/{store}/product-groups/{group}/send-runs/{run}/stop', [VentaCartProductGroupController::class, 'sendRunStop'])->whereNumber('group')->whereNumber('run')->name('ext.ventacart.product-groups.send_run_stop');
    Route::post('/ventacart/{store}/product-groups/{group}/push-stock', [VentaCartProductGroupController::class, 'pushStock'])->name('ext.ventacart.product-groups.push-stock');
    Route::post('/ventacart/{store}/product-groups/{group}/push-prices', [VentaCartProductGroupController::class, 'pushPrices'])->name('ext.ventacart.product-groups.push-prices');
    Route::post('/ventacart/{store}/product-groups/{group}/add-products', [VentaCartProductGroupController::class, 'addProducts'])->name('ext.ventacart.product-groups.addProducts');
    Route::post('/ventacart/{store}/product-groups/{group}/mass-remove', [VentaCartProductGroupController::class, 'massRemove'])->name('ext.ventacart.product-groups.mass-remove');
    Route::post('/ventacart/{store}/product-groups/move', [VentaCartProductGroupController::class, 'moveProducts'])->name('ext.ventacart.product-groups.move');
    Route::post('/ventacart/{store}/product-groups/{group}/check', [VentaCartProductGroupController::class, 'checkAgainstVenta'])->name('ext.ventacart.product-groups.check');
    Route::delete('/ventacart/{store}/product-groups/{group}/products/{product}', [VentaCartProductGroupController::class, 'removeProduct'])->name('ext.ventacart.product-groups.removeProduct');

    Route::post('/ventacart/{store}/product-groups/{group}/products/{product}/sync-id', [VentaCartProductGroupController::class, 'syncId'])->name('ext.ventacart.product-groups.sync-id');
    Route::post('/ventacart/{store}/product-groups/{group}/products/{product}/unlink', [VentaCartProductGroupController::class, 'unlinkProduct'])->name('ext.ventacart.product-groups.unlink');
    Route::post('/ventacart/{store}/product-groups/{group}/products/{product}/delete-from-ventacart', [VentaCartProductGroupController::class, 'deleteFromVenta'])->name('ext.ventacart.product-groups.deleteFromVenta');
    Route::post('/ventacart/{store}/product-groups/{group}/delete-from-ventacart', [VentaCartProductGroupController::class, 'deleteFromVenta'])->name('ext.ventacart.product-groups.bulk-delete-from-ventacart');
    Route::post('/ventacart/{store}/product-groups/{group}/products/{product}/link', [VentaCartProductGroupController::class, 'linkProduct'])->name('ext.ventacart.product-groups.link');

    Route::post('/ventacart/{store}/listings/refresh-status', [VentaCartListingController::class, 'refreshStatus'])->name('ext.ventacart.listings.refresh_status');
    Route::post('/ventacart/{store}/listings/bulk-toggle', [VentaCartListingController::class, 'bulkToggle'])->name('ext.ventacart.listings.bulk_toggle');
    Route::put('/ventacart/{store}/listings/{productId}', [VentaCartListingController::class, 'update'])->whereNumber('productId')->name('ext.ventacart.listings.update');
    Route::post('/ventacart/{store}/listings/{productId}/catalog-change', [VentaCartListingController::class, 'catalogChangeSave'])->whereNumber('productId')->name('ext.ventacart.listings.catalog_change_save');
    Route::post('/ventacart/{store}/listings/{productId}/catalog-change/ignore', [VentaCartListingController::class, 'catalogChangeIgnore'])->whereNumber('productId')->name('ext.ventacart.listings.catalog_change_ignore');
    Route::post('/ventacart/{store}/listings/{productId}/push', [VentaCartListingController::class, 'push'])->whereNumber('productId')->name('ext.ventacart.listings.push');
    Route::post('/ventacart/{store}/listings/{productId}/toggle', [VentaCartListingController::class, 'toggle'])->whereNumber('productId')->name('ext.ventacart.listings.toggle');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/ventacart/{store}/orders', [VentaCartOrderController::class, 'index'])->name('ext.ventacart.orders.index');

    Route::get('/ventacart/{store}/orders/{order}/awb', [VentaCartOrderController::class, 'awb'])->whereNumber('order')->name('ext.ventacart.orders.awb');
    Route::get('/ventacart/{store}/orders/{order}/tracking', [VentaCartOrderController::class, 'tracking'])->whereNumber('order')->name('ext.ventacart.orders.tracking');
    Route::get('/ventacart/{store}/orders/{order}/serviceability', [VentaCartOrderController::class, 'serviceability'])->whereNumber('order')->name('ext.ventacart.orders.serviceability');
    Route::get('/ventacart/{store}/orders/{order}/pickup-slots', [VentaCartOrderController::class, 'pickupSlots'])->whereNumber('order')->name('ext.ventacart.orders.pickup_slots');
    Route::get('/ventacart/{store}/orders/shipping-couriers', [VentaCartOrderController::class, 'shippingCouriers'])->name('ext.ventacart.orders.shipping_couriers');
    Route::get('/ventacart/{store}/orders/pickup-addresses', [VentaCartOrderController::class, 'pickupAddresses'])->name('ext.ventacart.orders.pickup_addresses');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/ventacart/{store}/orders/fetch', [VentaCartOrderController::class, 'fetch'])->name('ext.ventacart.orders.fetch');
    Route::post('/ventacart/{store}/orders/fetch-runs', [VentaCartOrderController::class, 'fetchRunBegin'])->name('ext.ventacart.orders.fetch_run_begin');
    Route::post('/ventacart/{store}/orders/fetch-runs/{run}/step', [VentaCartOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.ventacart.orders.fetch_run_step');
    Route::post('/ventacart/{store}/orders/fetch-runs/{run}/stop', [VentaCartOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.ventacart.orders.fetch_run_stop');
    Route::get('/ventacart/{store}/orders/fetch-runs/{run}', [VentaCartOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.ventacart.orders.fetch_run_state');

    Route::post('/ventacart/{store}/orders/{order}/estimate', [VentaCartOrderController::class, 'estimate'])->whereNumber('order')->name('ext.ventacart.orders.estimate');
    Route::post('/ventacart/{store}/orders/{order}/book', [VentaCartOrderController::class, 'book'])->whereNumber('order')->middleware(['packing.check:ventacart', 'booking.lock:ventacart'])->name('ext.ventacart.orders.book');
    Route::post('/ventacart/{store}/orders/{order}/cancel-booking', [VentaCartOrderController::class, 'cancelBooking'])->whereNumber('order')->middleware('booking.lock:ventacart')->name('ext.ventacart.orders.cancel_booking');
    Route::post('/ventacart/{store}/orders/{order}/book-manual', [VentaCartOrderController::class, 'bookManual'])->whereNumber('order')->middleware(['packing.check:ventacart', 'booking.lock:ventacart'])->name('ext.ventacart.orders.book_manual');
    Route::post('/ventacart/{store}/orders/{order}/clear-manual', [VentaCartOrderController::class, 'clearManual'])->whereNumber('order')->name('ext.ventacart.orders.clear_manual');
    Route::delete('/ventacart/{store}/orders/{order}', [VentaCartOrderController::class, 'destroy'])->name('ext.ventacart.orders.destroy');
    Route::post('/ventacart/{store}/orders/bulk-delete', [VentaCartOrderController::class, 'bulkDelete'])->name('ext.ventacart.orders.bulk_delete');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/ventacart/reviews/{id}/push', [VentaCartReviewController::class, 'push'])->name('ext.ventacart.reviews.push')->whereNumber('id');
    Route::post('/ventacart/reviews/push-all', [VentaCartReviewController::class, 'pushAll'])->name('ext.ventacart.reviews.push_all');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/ventacart/{store?}', [\Extensions\ventacart\Controllers\VentaCartDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.ventacart.dashboard');
});
