<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function renames(): array
    {
        return [
            ['lazada_orders', 'lazada_setting_id', 'store_id'],
            ['lazada_product_groups', 'lazada_setting_id', 'store_id'],
            ['lazada_products', 'lazada_setting_id', 'store_id'],
            ['lazada_reverse_orders', 'lazada_setting_id', 'store_id'],
            ['marketplace_reviews', 'opencart_setting_id', 'opencart_store_id'],
            ['marketplace_reviews', 'ventacart_setting_id', 'ventacart_store_id'],
            ['marketplace_reviews', 'woocommerce_setting_id', 'woocommerce_store_id'],
            ['opencart_order_status_map', 'opencart_setting_id', 'store_id'],
            ['opencart_product_groups', 'opencart_setting_id', 'store_id'],
            ['opencart_product_links', 'opencart_setting_id', 'store_id'],
            ['opencart_sync_log', 'opencart_setting_id', 'store_id'],
            ['shopee_api_logs', 'shopee_setting_id', 'store_id'],
            ['shopee_item_cache', 'shopee_setting_id', 'store_id'],
            ['shopee_listings', 'shopee_setting_id', 'store_id'],
            ['shopee_logistics', 'shopee_setting_id', 'store_id'],
            ['shopee_orders', 'shopee_setting_id', 'store_id'],
            ['shopee_product_groups', 'shopee_setting_id', 'store_id'],
            ['shopee_product_links', 'shopee_setting_id', 'store_id'],
            ['shopee_returns', 'shopee_setting_id', 'store_id'],
            ['shopify_api_logs', 'shopify_setting_id', 'store_id'],
            ['shopify_order_status_map', 'shopify_setting_id', 'store_id'],
            ['shopify_orders', 'shopify_setting_id', 'store_id'],
            ['shopify_product_links', 'shopify_setting_id', 'store_id'],
            ['shopify_sync_logs', 'shopify_setting_id', 'store_id'],
            ['tiktok_description_images', 'tiktok_setting_id', 'store_id'],
            ['tiktok_listings', 'tiktok_setting_id', 'store_id'],
            ['tiktok_orders', 'tiktok_setting_id', 'store_id'],
            ['tiktok_product_groups', 'tiktok_setting_id', 'store_id'],
            ['tiktok_returns', 'tiktok_setting_id', 'store_id'],
            ['ventacart_api_logs', 'ventacart_setting_id', 'store_id'],
            ['ventacart_brands', 'ventacart_setting_id', 'store_id'],
            ['ventacart_categories', 'ventacart_setting_id', 'store_id'],
            ['ventacart_listings', 'ventacart_setting_id', 'store_id'],
            ['ventacart_order_status_map', 'ventacart_setting_id', 'store_id'],
            ['ventacart_orders', 'ventacart_setting_id', 'store_id'],
            ['ventacart_product_groups', 'ventacart_setting_id', 'store_id'],
            ['ventacart_product_links', 'ventacart_setting_id', 'store_id'],
            ['ventacart_status_placements', 'ventacart_setting_id', 'store_id'],
            ['ventacart_sync_logs', 'ventacart_setting_id', 'store_id'],
            ['woocommerce_api_logs', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_categories', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_listings', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_order_status_map', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_orders', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_product_groups', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_product_links', 'woocommerce_setting_id', 'store_id'],
            ['woocommerce_sync_logs', 'woocommerce_setting_id', 'store_id'],
        ];
    }

    public function up(): void
    {
        foreach ($this->renames() as [$table, $from, $to]) {
            $this->rename($table, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->renames()) as [$table, $from, $to]) {
            $this->rename($table, $to, $from);
        }
    }

    private function rename(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            \App\Support\Database\ColumnRename::run($table, $from, $to);
        }
    }
};
