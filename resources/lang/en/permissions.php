<?php

/*
|--------------------------------------------------------------------------
| Permission Display Strings
|--------------------------------------------------------------------------
|
| Wording for the route-derived permission catalogue - see
| App\Services\PermissionCatalogue. Keys are {area}/{controller}; every
| entry here OVERRIDES a derivation, never enables one: a key with no
| wording still renders, humanised, so coverage never waits on prose.
|
|   areas    — the section headings ('catalog' => 'Catalogue')
|   notes    — one line under an area heading, where the name is not enough
|   rows     — row titles ('lazada/order' => ...)
|   warnings — the plain sentence under a row that hands over more than
|              its own screen; see PermissionCatalogue::warningFor()
|
| The per-key label/description blocks that used to fill this file named
| the retired scheme (view_products, manage_orders, ...) and left with it.
|
*/

return [

    'areas' => [
        'catalog' => 'Catalogue',
        'sales' => 'Sales',
        'settings' => 'Settings',
        'general' => 'General',
        'audit' => 'Audit & Monitoring',

        // Nobody clicks these. The phone app and API tokens sign in against
        // them, so they are named for the audience rather than the code.
        'api' => 'Mobile app access',
        'api_v1' => 'Partner API access',
    ],

    /*
    |--------------------------------------------------------------------------
    | Area notes
    |--------------------------------------------------------------------------
    |
    | One line under an area heading, for the areas whose name is not enough
    | on its own. Most need none - "Catalogue" explains itself.
    |
    */

    'warnings' => [
        // One plain sentence under the rows that hand over more than their
        // own screen. The api_explorer rows carry theirs from the rule in
        // PermissionCatalogue::warningFor() - no per-channel prose needed.
        'settings/user_group' => 'sets what every group may do, this one included',
        'settings/user' => 'creates logins and moves people between groups',
        'settings/api_client' => 'issues keys that reach the data from outside',
        'settings/extension' => 'installs code that runs with full access',
    ],

    'notes' => [
        'api' => 'What the VentaSync phone app may reach when this group signs in on it. Nothing here appears in the web interface.',
        'api_v1' => 'What an outside system may read using an API client key issued to this group.',
    ],

    'rows' => [
        // Machine-facing rows, named for what the caller is doing rather than
        // for the controller answering. "Auth" and "Device Token" are what
        // the code calls them; neither tells an administrator anything.
        'api/auth' => 'Sign in on the phone app',
        'api/device_token' => 'Push notifications',
        'api/dashboard' => 'Dashboard figures',
        'api/catalog' => 'Browse the catalogue',
        'api/product' => 'Products',
        'api/order' => 'Orders',
        'api/marketplace_order' => 'Marketplace orders',
        'api/marketplace_config' => 'Channel settings',
        'api_v1/product' => 'Products',
        'api_v1/order' => 'Orders',
        'api_v1/inventory' => 'Inventory',

        // Str::headline stutters a controller whose class name already ends
        // in Api and sits in an api_ file: "Api Report Api".
        'reports/api_report_api' => 'Reports over the API',
        'venta/api_venta_order_api' => 'Orders over the API',

        // The bare controller here is the ledger, not the settings:
        // Petty Cash keeps those in their own controller.
        'pettycash/ledger' => 'Transactions',
        // A channel's settings row is its connection: the credentials, the
        // sandbox/live switch, the status maps. The API explorer - sending
        // requests to the marketplace as the shop - is its own row now, and
        // its own grant decision. Str::headline says "Settings" and "Api
        // Explorer" on its own; only the explorer needs better words.
        'lazada/settings' => 'Connection',
        'shopee/settings' => 'Connection',
        'tiktok/settings' => 'Connection',
        'venta/settings' => 'Connection',
        'opencart/settings' => 'Connection',
        'pedallion/settings' => 'Connection',
        'shopify/settings' => 'Connection',
        'lazada/api_explorer' => 'API explorer',
        'shopee/api_explorer' => 'API explorer',
        'tiktok/api_explorer' => 'API explorer',
        'pedallion/api_explorer' => 'API explorer',

        // "Warehouse Transfer" inside a group already titled Warehousing
        // says warehouse twice.
        'warehousing/warehouse' => 'Locations',
        'warehousing/warehouse_inventory' => 'Inventory',
        'warehousing/warehouse_transfer' => 'Transfers',

        'settings/api_client' => 'API Clients',
        'settings/settings_hub' => 'Settings Home',
        'settings/setting' => 'General Settings',
        'catalog/product_option' => 'Product Variations',
        'reports/report' => 'Reports',
        'audit/activity_log' => 'Activity Log',
    ],
];
