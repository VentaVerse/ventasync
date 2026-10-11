<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        ['channel_webhook_events', 'ch_channel_webhook_events'],
        ['description_templates', 'ch_description_templates'],
        ['lazada_api_logs', 'ch_lazada_api_logs'],
        ['lazada_brands', 'ch_lazada_brands'],
        ['lazada_categories', 'ch_lazada_categories'],
        ['lazada_category_templates', 'ch_lazada_category_templates'],
        ['lazada_image_links', 'ch_lazada_image_links'],
        ['lazada_listing_attributes', 'ch_lazada_listing_attributes'],
        ['lazada_listing_variations', 'ch_lazada_listing_variations'],
        ['lazada_listings', 'ch_lazada_listings'],
        ['lazada_order_products', 'ch_lazada_order_products'],
        ['lazada_order_status_map', 'ch_lazada_order_status_map'],
        ['lazada_orders', 'ch_lazada_orders'],
        ['lazada_product_group_attributes', 'ch_lazada_product_group_attributes'],
        ['lazada_product_group_products', 'ch_lazada_product_group_products'],
        ['lazada_product_groups', 'ch_lazada_product_groups'],
        ['lazada_reverse_orders', 'ch_lazada_reverse_orders'],
        ['lazada_settings', 'ch_lazada_stores'],
        ['listing_readiness', 'ch_listing_readiness'],
        ['listing_store_skus', 'ch_listing_store_skus'],
        ['listing_variation_offs', 'ch_listing_variation_offs'],
        ['marketplace_reviews', 'ch_marketplace_reviews'],
        ['opencart_listing_links', 'ch_opencart_listing_links'],
        ['opencart_order_status_map', 'ch_opencart_order_status_map'],
        ['opencart_product_group_products', 'ch_opencart_product_group_products'],
        ['opencart_product_groups', 'ch_opencart_product_groups'],
        ['opencart_settings', 'ch_opencart_stores'],
        ['opencart_sync_log', 'ch_opencart_sync_log'],
        ['order_fetch_runs', 'ch_order_fetch_runs'],
        ['pedallion_api_logs', 'ch_pedallion_api_logs'],
        ['pedallion_categories', 'ch_pedallion_categories'],
        ['pedallion_manufacturers', 'ch_pedallion_manufacturers'],
        ['pedallion_order_products', 'ch_pedallion_order_products'],
        ['pedallion_order_status_map', 'ch_pedallion_order_status_map'],
        ['pedallion_orders', 'ch_pedallion_orders'],
        ['pedallion_product_group_products', 'ch_pedallion_product_group_products'],
        ['pedallion_product_groups', 'ch_pedallion_product_groups'],
        ['pedallion_product_links', 'ch_pedallion_product_links'],
        ['pedallion_settings', 'ch_pedallion_stores'],
        ['shopee_api_logs', 'ch_shopee_api_logs'],
        ['shopee_brands', 'ch_shopee_brands'],
        ['shopee_categories', 'ch_shopee_categories'],
        ['shopee_category_templates', 'ch_shopee_category_templates'],
        ['shopee_item_cache', 'ch_shopee_item_cache'],
        ['shopee_listing_links', 'ch_shopee_listing_links'],
        ['shopee_listings', 'ch_shopee_listings'],
        ['shopee_logistics', 'ch_shopee_logistics'],
        ['shopee_order_products', 'ch_shopee_order_products'],
        ['shopee_order_status_map', 'ch_shopee_order_status_map'],
        ['shopee_orders', 'ch_shopee_orders'],
        ['shopee_product_group_attributes', 'ch_shopee_product_group_attributes'],
        ['shopee_product_group_products', 'ch_shopee_product_group_products'],
        ['shopee_product_groups', 'ch_shopee_product_groups'],
        ['shopee_returns', 'ch_shopee_returns'],
        ['shopee_settings', 'ch_shopee_stores'],
        ['shopify_api_logs', 'ch_shopify_api_logs'],
        ['shopify_listing_links', 'ch_shopify_listing_links'],
        ['shopify_order_products', 'ch_shopify_order_products'],
        ['shopify_order_status_map', 'ch_shopify_order_status_map'],
        ['shopify_orders', 'ch_shopify_orders'],
        ['shopify_settings', 'ch_shopify_stores'],
        ['shopify_sync_logs', 'ch_shopify_sync_logs'],
        ['tiktok_api_logs', 'ch_tiktok_api_logs'],
        ['tiktok_categories', 'ch_tiktok_categories'],
        ['tiktok_category_templates', 'ch_tiktok_category_templates'],
        ['tiktok_description_images', 'ch_tiktok_description_images'],
        ['tiktok_listings', 'ch_tiktok_listings'],
        ['tiktok_order_products', 'ch_tiktok_order_products'],
        ['tiktok_order_status_map', 'ch_tiktok_order_status_map'],
        ['tiktok_orders', 'ch_tiktok_orders'],
        ['tiktok_product_group_attributes', 'ch_tiktok_product_group_attributes'],
        ['tiktok_product_group_products', 'ch_tiktok_product_group_products'],
        ['tiktok_product_groups', 'ch_tiktok_product_groups'],
        ['tiktok_returns', 'ch_tiktok_returns'],
        ['tiktok_settings', 'ch_tiktok_stores'],
        ['ventacart_api_logs', 'ch_ventacart_api_logs'],
        ['ventacart_brands', 'ch_ventacart_brands'],
        ['ventacart_categories', 'ch_ventacart_categories'],
        ['ventacart_listing_links', 'ch_ventacart_listing_links'],
        ['ventacart_listings', 'ch_ventacart_listings'],
        ['ventacart_order_products', 'ch_ventacart_order_products'],
        ['ventacart_order_status_map', 'ch_ventacart_order_status_map'],
        ['ventacart_orders', 'ch_ventacart_orders'],
        ['ventacart_product_group_products', 'ch_ventacart_product_group_products'],
        ['ventacart_product_groups', 'ch_ventacart_product_groups'],
        ['ventacart_settings', 'ch_ventacart_stores'],
        ['ventacart_status_placements', 'ch_ventacart_status_placements'],
        ['ventacart_sync_logs', 'ch_ventacart_sync_logs'],
        ['watermark_templates', 'ch_watermark_templates'],
        ['woocommerce_api_logs', 'ch_woocommerce_api_logs'],
        ['woocommerce_categories', 'ch_woocommerce_categories'],
        ['woocommerce_listing_links', 'ch_woocommerce_listing_links'],
        ['woocommerce_listings', 'ch_woocommerce_listings'],
        ['woocommerce_order_products', 'ch_woocommerce_order_products'],
        ['woocommerce_order_status_map', 'ch_woocommerce_order_status_map'],
        ['woocommerce_orders', 'ch_woocommerce_orders'],
        ['woocommerce_product_group_products', 'ch_woocommerce_product_group_products'],
        ['woocommerce_product_groups', 'ch_woocommerce_product_groups'],
        ['woocommerce_settings', 'ch_woocommerce_stores'],
        ['woocommerce_sync_logs', 'ch_woocommerce_sync_logs'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$from, $to]) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as [$from, $to]) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
