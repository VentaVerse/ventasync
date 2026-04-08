<?php

/**
 * Permission labels & descriptions.
 *
 * The Permission model resolves display text from this file via
 * __('permissions.<key>.label') and __('permissions.<key>.description').
 * Changing wording here is all it takes — no migrations, no tinker.
 */

return [

    // ── Catalog ───────────────────────────────────────────────
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

    // ── Sales ─────────────────────────────────────────────────
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
    'manage_reports' => [
        'label'       => 'Reports',
        'description' => 'View order, product, category, fee, and inventory reports',
    ],

    // ── Marketplace API ───────────────────────────────────────
    'manage_marketplace_api' => [
        'label'       => 'Marketplace API (All)',
        'description' => 'Full access to all marketplace integrations',
    ],
    'manage_shopee' => [
        'label'       => 'Shopee',
        'description' => 'Shopee store settings and product sync',
    ],
    'manage_shopee_orders' => [
        'label'       => 'Shopee Orders',
        'description' => 'View and sync orders from Shopee',
    ],
    'manage_lazada' => [
        'label'       => 'Lazada',
        'description' => 'Lazada store settings and product sync',
    ],
    'manage_lazada_orders' => [
        'label'       => 'Lazada Orders',
        'description' => 'View and sync orders from Lazada',
    ],
    'manage_tiktok' => [
        'label'       => 'TikTok Shop',
        'description' => 'TikTok Shop settings and product sync',
    ],
    'manage_tiktok_orders' => [
        'label'       => 'TikTok Orders',
        'description' => 'View and sync orders from TikTok Shop',
    ],
    'manage_opencart' => [
        'label'       => 'OpenCart',
        'description' => 'OpenCart store integration and product sync',
    ],
    'manage_opencart_orders' => [
        'label'       => 'OpenCart Orders',
        'description' => 'View and sync orders from OpenCart',
    ],
    'manage_venta' => [
        'label'       => 'Venta',
        'description' => 'Venta store settings, product groups, and product sync',
    ],
    'manage_venta_orders' => [
        'label'       => 'Venta Orders',
        'description' => 'View and sync orders from Venta stores',
    ],
    'manage_pedallion' => [
        'label'       => 'Pedallion',
        'description' => 'Pedallion store settings and product sync',
    ],
    'manage_pedallion_orders' => [
        'label'       => 'Pedallion Orders',
        'description' => 'View and sync orders from Pedallion',
    ],
    'manage_reviews' => [
        'label'       => 'Reviews',
        'description' => 'View marketplace reviews and manage review sync',
    ],

    // ── Purchasing ────────────────────────────────────────────
    'manage_purchasing' => [
        'label'       => 'Purchasing',
        'description' => 'Full access to all purchasing features',
    ],
    'manage_vendors' => [
        'label'       => 'Vendors',
        'description' => 'View, create, edit, and delete vendors',
    ],
    'manage_purchase_orders' => [
        'label'       => 'Purchase Orders',
        'description' => 'View, create, and manage purchase orders',
    ],

    // ── Warehousing ───────────────────────────────────────────
    'manage_warehousing' => [
        'label'       => 'Warehousing',
        'description' => 'Full access to all warehousing features',
    ],
    'manage_warehouse_locations' => [
        'label'       => 'Warehouse Locations',
        'description' => 'View and manage warehouse locations',
    ],
    'manage_warehouse_transfers' => [
        'label'       => 'Warehouse Transfers',
        'description' => 'View, create, and manage stock transfers',
    ],
    'manage_warehouse_inventory' => [
        'label'       => 'Warehouse Inventory',
        'description' => 'View and adjust per-warehouse stock levels',
    ],

    // ── Petty Cash ────────────────────────────────────────────
    'view_petty_cash' => [
        'label'       => 'Petty Cash',
        'description' => 'See the Petty Cash nav group',
    ],
    'view_petty_cash_transactions' => [
        'label'       => 'Petty Cash Transactions',
        'description' => 'View and manage petty cash transactions',
    ],
    'manage_petty_cash_settings' => [
        'label'       => 'Petty Cash Settings',
        'description' => 'Access petty cash settings and role management',
    ],

    // ── Settings ──────────────────────────────────────────────
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
    'manage_activity_log' => [
        'label'       => 'Activity Log',
        'description' => 'View and clear the activity log',
    ],

    // ── Legacy (kept for completeness) ────────────────────────
    'manage_users_groups' => [
        'label'       => 'Users & Groups',
        'description' => null,
    ],

];
