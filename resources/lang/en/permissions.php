<?php

/*
|--------------------------------------------------------------------------
| Core Permission Display Strings
|--------------------------------------------------------------------------
|
| Labels + descriptions for permissions declared by core in
| config/permissions.php. Extension-owned permissions are NOT listed here
| — each extension ships its own lang file at
| extensions/{id}/lang/{locale}/permissions.php, auto-registered under the
| `ext-{id}` translation namespace by ExtensionProvider::bootTranslations().
|
| The Permission model resolves display strings in this order:
|   1. ext-{owner}::permissions.{key}.{label|description}  (extension lang)
|   2. permissions.{key}.{label|description}               (this file)
|   3. humanized key
|
*/

return [

    // ── Catalog (core) ────────────────────────────────────────
    'manage_catalog' => [
        'label'       => 'Catalog (All)',
        'description' => 'Full access to all catalog features',
    ],
    'manage_products' => [
        'label'       => 'Products',
        'description' => 'View, create, edit, and delete products',
    ],
    'manage_categories' => [
        'label'       => 'Categories',
        'description' => 'View, create, edit, and delete categories',
    ],
    'manage_manufacturers' => [
        'label'       => 'Manufacturers',
        'description' => 'View, create, edit, and delete manufacturers',
    ],
    'manage_options' => [
        'label'       => 'Options',
        'description' => 'View, create, edit, and delete product options',
    ],

    // ── Sales (core) ──────────────────────────────────────────
    'manage_sales' => [
        'label'       => 'Sales (All)',
        'description' => 'Full access to all sales features',
    ],
    'manage_orders' => [
        'label'       => 'Orders',
        'description' => 'View and manage customer orders',
    ],
    'manage_order_statuses' => [
        'label'       => 'Order Statuses',
        'description' => 'Create and edit order status definitions',
    ],

    // ── Marketplace API container (core; children come from extensions) ──
    'manage_marketplace_api' => [
        'label'       => 'Marketplace API (All)',
        'description' => 'Full access to all marketplace integrations',
    ],

    // ── Settings (core) ───────────────────────────────────────
    'manage_settings' => [
        'label'       => 'Settings (All)',
        'description' => 'Full access to all system settings',
    ],
    'manage_users' => [
        'label'       => 'Users',
        'description' => 'View, create, edit, and deactivate users',
    ],
    'manage_user_groups' => [
        'label'       => 'User Groups',
        'description' => 'Manage user groups and permissions',
    ],
    'manage_website_settings' => [
        'label'       => 'Website Settings',
        'description' => 'Edit global website configuration',
    ],
    'manage_error_log' => [
        'label'       => 'Error Log',
        'description' => 'View and clear application error log',
    ],

    // ── Legacy (kept for hasPermission() back-compat) ─────────
    'manage_users_groups' => [
        'label'       => 'Users & Groups',
        'description' => null,
    ],

];
