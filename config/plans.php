<?php

$extensions = [];
foreach (glob(base_path('extensions/*/extension.json')) ?: [] as $manifest) {
    $id = basename(dirname($manifest));
    $extensions[$id] = env('VENTASYNC_EXT_' . strtoupper(str_replace('-', '_', $id)));
}

return [

    'extensions' => $extensions,

    'limits' => [
        'products' => env('VENTASYNC_MAX_PRODUCTS'),
        'users' => env('VENTASYNC_MAX_USERS'),
        'stores' => env('VENTASYNC_MAX_STORES'),
        'api_apps' => env('VENTASYNC_MAX_API_APPS'),
        'orders_month' => env('VENTASYNC_MAX_ORDERS_MONTH'),
        'log_days' => env('VENTASYNC_LOG_DAYS'),
        'activity_days' => env('VENTASYNC_ACTIVITY_DAYS'),
        'awb_days' => env('VENTASYNC_AWB_DAYS'),
    ],

];
