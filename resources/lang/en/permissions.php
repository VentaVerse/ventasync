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
| Phase 4.5 — every core resource has a view_X + manage_X twin. The view
| tier grants read-only access (index, show, edit-form render where
| applicable); the manage tier grants the full create/update/delete
| toolkit on top. "Manage implies view" cascades in User::hasPermission.
|
*/

return [

    // ── Catalog (core) ────────────────────────────────────────
    'view_catalog' => [
        'label'       => 'Catalog (View All)',
        'description' => 'Read-only access to every resource in the catalog domain',
    ],
    'manage_catalog' => [
        'label'       => 'Catalog (All)',
        'description' => 'Full access to all catalog features',
    ],
    'view_products' => [
        'label'       => 'View Products',
        'description' => 'Browse the product catalog (read-only)',
    ],
    'manage_products' => [
        'label'       => 'Manage Products',
        'description' => 'View, create, edit, and delete products',
    ],
    'view_categories' => [
        'label'       => 'View Categories',
        'description' => 'Browse the category tree (read-only)',
    ],
    'manage_categories' => [
        'label'       => 'Manage Categories',
        'description' => 'View, create, edit, and delete categories',
    ],
    'view_manufacturers' => [
        'label'       => 'View Manufacturers',
        'description' => 'Browse manufacturers (read-only)',
    ],
    'manage_manufacturers' => [
        'label'       => 'Manage Manufacturers',
        'description' => 'View, create, edit, and delete manufacturers',
    ],

    // ── Sales (core) ──────────────────────────────────────────
    'view_sales' => [
        'label'       => 'Sales (View All)',
        'description' => 'Read-only access to every resource in the sales domain',
    ],
    'manage_sales' => [
        'label'       => 'Sales (All)',
        'description' => 'Full access to all sales features',
    ],
    'view_orders' => [
        'label'       => 'View Orders',
        'description' => 'Browse customer orders and view order details (read-only)',
    ],
    'manage_orders' => [
        'label'       => 'Manage Orders',
        'description' => 'View and manage customer orders, payments, statuses, and fees',
    ],
    'view_order_statuses' => [
        'label'       => 'View Order Statuses',
        'description' => 'Browse order status definitions (read-only)',
    ],
    'manage_order_statuses' => [
        'label'       => 'Manage Order Statuses',
        'description' => 'Create and edit order status definitions',
    ],

    // ── Marketplace API container (core; children come from extensions) ──
    'view_marketplace_api' => [
        'label'       => 'Marketplace API (View All)',
        'description' => 'Read-only access to every marketplace integration',
    ],
    'manage_marketplace_api' => [
        'label'       => 'Marketplace API (All)',
        'description' => 'Full access to all marketplace integrations',
    ],

    // ── Settings (core) ───────────────────────────────────────
    'view_settings' => [
        'label'       => 'Settings (View All)',
        'description' => 'Read-only access to every system settings page',
    ],
    'manage_settings' => [
        'label'       => 'Settings (All)',
        'description' => 'Full access to all system settings',
    ],
    'view_users' => [
        'label'       => 'View Users',
        'description' => 'Browse the user list (read-only)',
    ],
    'manage_users' => [
        'label'       => 'Manage Users',
        'description' => 'View, create, edit, and deactivate users',
    ],
    'view_user_groups' => [
        'label'       => 'View User Groups',
        'description' => 'Browse user groups and their permission assignments (read-only)',
    ],
    'manage_user_groups' => [
        'label'       => 'Manage User Groups',
        'description' => 'Create, edit, and delete user groups and their permissions',
    ],
    'view_website_settings' => [
        'label'       => 'View Website Settings',
        'description' => 'Browse website settings, currencies, and extensions (read-only)',
    ],
    'manage_website_settings' => [
        'label'       => 'Manage Website Settings',
        'description' => 'Edit global website configuration, currencies, and extensions',
    ],
    'view_error_log' => [
        'label'       => 'View Error Log',
        'description' => 'Read the application error log (read-only)',
    ],
    'manage_error_log' => [
        'label'       => 'Manage Error Log',
        'description' => 'View and clear application error log',
    ],
    'view_api_clients' => [
        'label'       => 'View API Applications',
        'description' => 'Browse API applications and their audit logs (read-only)',
    ],
    'manage_api_clients' => [
        'label'       => 'Manage API Applications',
        'description' => 'Create, rotate, and revoke API applications and their tokens',
    ],

    // ── Legacy (kept for hasPermission() back-compat) ─────────
    'manage_users_groups' => [
        'label'       => 'Users & Groups',
        'description' => null,
    ],

];
