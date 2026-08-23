<?php

namespace App\Services;

/**
 * THE FROZEN TRANSLATION TABLE FROM THE RETIRED PERMISSION SCHEME.
 *
 * legacy key => the derived keys that cover the routes it used to gate.
 *
 * Until 2026-08-23 this mapping was derived at runtime by reading the
 * perm: middleware off the route table - which was correct exactly as long
 * as the perm: arguments existed. Removing them (Phase A of the one-
 * vocabulary plan) would have struck the transition shim blind and locked
 * every pre-rebuild group out at the door. So the mapping is frozen here,
 * generated from the route table's last reading BEFORE the arguments came
 * off, plus the same-shaped entries for the extensions that were disabled
 * at capture time (pedallion, shopify, audit).
 *
 * THIS IS SHIM DATA, NOT ARCHITECTURE. It exists so a group still holding
 * an old key keeps exactly the access it had, and it dies in Phase D with
 * the shims and the legacy rows. Nothing may add entries for NEW
 * functionality - a new page derives a new key and needs no translation.
 */
final class LegacyGrantMap
{
    /** @var array<string, array<int, string>> */
    public const TRANSLATIONS = [
        'manage_activity_log' => ['manage_audit/activity_log'],
        'manage_api_clients' => ['manage_settings/api_client'],
        'manage_audit' => ['manage_audit/activity_log'],
        'manage_catalog' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_categories' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_error_log' => ['manage_settings/error_log'],
        'manage_lazada' => ['manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/product_group'],
        'manage_lazada_orders' => ['manage_lazada/order'],
        'manage_manufacturers' => ['manage_api/catalog', 'manage_catalog/category', 'manage_catalog/manufacturer'],
        'manage_opencart' => ['manage_opencart/settings', 'manage_opencart/product_group'],
        'manage_opencart_orders' => ['manage_opencart/order'],
        'manage_order_statuses' => ['manage_settings/order_status'],
        'manage_orders' => ['manage_api/order', 'manage_sales/order'],
        'manage_pedallion' => ['manage_pedallion/category', 'manage_pedallion/settings', 'manage_pedallion/product', 'manage_pedallion/product_group'],
        'manage_pedallion_orders' => ['manage_pedallion/order'],
        'manage_petty_cash_settings' => ['manage_pettycash/settings'],
        'manage_petty_cash_transactions' => ['manage_pettycash/ledger'],
        'manage_products' => ['manage_api/catalog', 'manage_api/product', 'manage_catalog/category', 'manage_catalog/manufacturer', 'manage_catalog/product', 'manage_catalog/product_image', 'manage_catalog/product_option', 'manage_purchasing/product_vendor'],
        'manage_purchase_orders' => ['manage_purchasing/purchase_order', 'manage_purchasing/reorder'],
        'manage_purchasing' => ['manage_purchasing/purchase_order', 'manage_purchasing/reorder', 'manage_purchasing/vendor'],
        'manage_reports' => ['manage_reports/report'],
        'manage_reviews' => ['manage_opencart/review'],
        'manage_shopee' => ['manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/settings', 'manage_shopee/product', 'manage_shopee/product_group'],
        'manage_shopee_orders' => ['manage_shopee/order'],
        'manage_shopify' => ['manage_shopify/settings'],
        'manage_shopify_orders' => ['manage_shopify/order'],
        'manage_tiktok' => ['manage_tiktok/settings', 'manage_tiktok/product_group'],
        'manage_tiktok_orders' => ['manage_tiktok/order'],
        'manage_user_groups' => ['manage_settings/user_group'],
        'manage_users' => ['manage_settings/user'],
        'manage_vendors' => ['manage_purchasing/vendor'],
        'manage_venta' => ['manage_venta/settings', 'manage_venta/product_group', 'manage_venta/review'],
        'manage_venta_orders' => ['manage_venta/api_venta_order_api', 'manage_venta/order'],
        'manage_warehouse_inventory' => ['manage_warehousing/warehouse_inventory'],
        'manage_warehouse_locations' => ['manage_warehousing/warehouse'],
        'manage_warehouse_transfers' => ['manage_warehousing/warehouse_transfer'],
        'manage_warehousing' => ['manage_warehousing/warehouse', 'manage_warehousing/warehouse_inventory', 'manage_warehousing/warehouse_transfer'],
        'manage_website_settings' => ['manage_settings/currency', 'manage_settings/extension', 'manage_settings/setting', 'manage_settings/settings_hub'],
        'view_activity_log' => ['view_audit/activity_log'],
        'view_api_clients' => ['view_settings/api_client'],
        'view_audit' => ['view_audit/activity_log'],
        'view_categories' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer'],
        'view_error_log' => ['view_settings/error_log'],
        'view_lazada' => ['view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/settings', 'view_lazada/product', 'view_lazada/product_group'],
        'view_lazada_orders' => ['view_lazada/order'],
        'view_manufacturers' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer'],
        'view_opencart' => ['view_opencart/settings', 'view_opencart/product_group'],
        'view_opencart_orders' => ['view_opencart/order'],
        'view_order_statuses' => ['view_settings/order_status'],
        'view_orders' => ['view_sales/order'],
        'view_pedallion' => ['view_pedallion/category', 'view_pedallion/settings', 'view_pedallion/product', 'view_pedallion/product_group'],
        'view_pedallion_orders' => ['view_pedallion/order'],
        'view_petty_cash' => ['view_pettycash/ledger', 'view_pettycash/settings'],
        'view_petty_cash_settings' => ['view_pettycash/settings'],
        'view_petty_cash_transactions' => ['view_pettycash/ledger'],
        'view_products' => ['view_api/catalog', 'view_catalog/category', 'view_catalog/manufacturer', 'view_catalog/product', 'view_catalog/product_image'],
        'view_purchase_orders' => ['view_purchasing/purchase_order'],
        'view_purchasing' => ['view_purchasing/purchase_order', 'view_purchasing/vendor'],
        'view_reports' => ['view_reports/report'],
        'view_reviews' => ['view_opencart/review'],
        'view_shopee' => ['view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/settings', 'view_shopee/product', 'view_shopee/product_group'],
        'view_shopee_orders' => ['view_shopee/order'],
        'view_shopify' => ['view_shopify/settings'],
        'view_shopify_orders' => ['view_shopify/order'],
        'view_tiktok' => ['view_tiktok/settings', 'view_tiktok/product_group'],
        'view_tiktok_orders' => ['view_tiktok/order'],
        'view_user_groups' => ['view_settings/user_group'],
        'view_users' => ['view_settings/user'],
        'view_vendors' => ['view_purchasing/vendor'],
        'view_venta' => ['view_venta/settings', 'view_venta/product_group'],
        'view_venta_orders' => ['view_venta/order'],
        'view_warehouse_inventory' => ['view_warehousing/warehouse_inventory'],
        'view_warehouse_locations' => ['view_warehousing/warehouse'],
        'view_warehouse_transfers' => ['view_warehousing/warehouse_transfer'],
        'view_warehousing' => ['view_warehousing/warehouse', 'view_warehousing/warehouse_inventory', 'view_warehousing/warehouse_transfer'],
        'view_website_settings' => ['view_settings/currency', 'view_settings/extension', 'view_settings/setting', 'view_settings/settings_hub'],
    ];
}
