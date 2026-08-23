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
        /* ONE RULE: A ROW IS TITLED EXACTLY AS ITS SCREEN IS TITLED IN THE
           MENUS. The owner's spec - the administrator already knows every menu,
           so a permission whose title matches the menu needs no further
           explanation. Where one key serves several menu entries (Orders +
           Returns, six report screens), the primary entry names it. Keys
           with no menu presence keep a plural, menu-shaped title. */

        // Machine-facing rows, named for what the caller is doing rather
        // than for the controller answering.
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
        // Six report screens, one key: the group name titles it.
        'reports/report' => 'Reports',
        'venta/api_venta_order_api' => 'Orders over the API',

        // Core, titled as the sidebar titles them.
        'sales/order' => 'Orders',
        'catalog/product' => 'Products',
        'catalog/category' => 'Categories',
        'catalog/manufacturer' => 'Manufacturers',
        'catalog/product_image' => 'Product Images',
        'catalog/product_option' => 'Variations',
        'purchasing/purchase_order' => 'Purchase Orders',
        'purchasing/vendor' => 'Vendors',
        'purchasing/reorder' => 'Reorder Suggestions',
        'purchasing/product_vendor' => 'Product Vendors',
        'settings/user' => 'Users',
        'settings/user_group' => 'User Groups',
        'settings/order_status' => 'Order Statuses',
        'settings/currency' => 'Currencies',
        'settings/extension' => 'Extensions',
        'settings/api_client' => 'API Clients',
        'settings/setting' => 'General Settings',
        'settings/error_log' => 'Error Log',
        'settings/settings_hub' => 'Settings Home',
        'pettycash/ledger' => 'Transactions',

        // Channel workspaces, titled as their own sidebars title them:
        // Overview, Orders, Listings, Product Groups, Categories, Brands,
        // Logistics, Settings. The API explorer has no menu entry - it
        // lives inside the Settings page - and is named for what it is.
        'lazada/dashboard' => 'Overview',
        'shopee/dashboard' => 'Overview',
        'tiktok/dashboard' => 'Overview',
        'venta/dashboard' => 'Overview',
        'opencart/dashboard' => 'Overview',
        'pedallion/dashboard' => 'Overview',
        'shopify/dashboard' => 'Overview',
        'lazada/order' => 'Orders',
        'shopee/order' => 'Orders',
        'tiktok/order' => 'Orders',
        'venta/order' => 'Orders',
        'opencart/order' => 'Orders',
        'pedallion/order' => 'Orders',
        'shopify/order' => 'Orders',
        'lazada/product' => 'Listings',
        'shopee/product' => 'Listings',
        'pedallion/product' => 'Listings',
        'lazada/product_group' => 'Product Groups',
        'shopee/product_group' => 'Product Groups',
        'tiktok/product_group' => 'Product Groups',
        'venta/product_group' => 'Product Groups',
        'opencart/product_group' => 'Product Groups',
        'pedallion/product_group' => 'Product Groups',
        'lazada/category' => 'Categories',
        'shopee/category' => 'Categories',
        'pedallion/category' => 'Reference Data',
        'lazada/category_attribute' => 'Category Attributes',
        'shopee/category_attribute' => 'Category Attributes',
        'lazada/brand' => 'Brands',
        'opencart/review' => 'Reviews',
        'lazada/api_explorer' => 'API explorer',
        'shopee/api_explorer' => 'API explorer',
        'tiktok/api_explorer' => 'API explorer',
        'pedallion/api_explorer' => 'API explorer',

        // "Warehouse Transfer" inside a group already titled Warehousing
        // says warehouse twice.
        'warehousing/warehouse' => 'Locations',
        'warehousing/warehouse_inventory' => 'Inventory',
        'warehousing/warehouse_transfer' => 'Transfers',
    ],
];
