<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        ['ch_shopee_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_lazada_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_tiktok_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_ventacart_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_woocommerce_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_shopify_orders', 'erp_order_id', 'sales_order_id'],
        ['ch_shopify_order_products', 'erp_order_product_id', 'sales_order_product_id'],
        ['ch_shopify_orders', 'shopify_created_at', 'order_created_at'],
        ['ch_shopify_orders', 'shopify_updated_at', 'order_updated_at'],
        ['ch_tiktok_orders', 'buyer_name', 'customer_name'],
        ['ch_shopee_orders', 'buyer_invoice', 'customer_invoice'],
        ['ch_woocommerce_orders', 'customer_phone', 'customer_telephone'],
        ['settings', 'phone', 'telephone'],
        ['suppliers', 'phone', 'telephone'],
        ['order_payments', 'notes', 'note'],
        ['petty_cash_transactions', 'notes', 'note'],
        ['product_suppliers', 'notes', 'note'],
        ['purchase_order_items', 'notes', 'note'],
        ['purchase_order_payments', 'notes', 'note'],
        ['purchase_orders', 'notes', 'note'],
        ['purchase_orders', 'internal_notes', 'internal_note'],
        ['suppliers', 'notes', 'note'],
        ['order_history', 'comment', 'note'],
        ['orders', 'comment', 'customer_note'],
        ['api_clients', 'active', 'enabled'],
        ['ch_ventacart_brands', 'is_active', 'enabled'],
        ['ch_ventacart_categories', 'is_active', 'enabled'],
    ];

    private const INDEXES = [
        ['ch_shopee_orders', 'ch_shopee_orders_catalog_order_id_index', 'ch_shopee_orders_sales_order_id_index'],
        ['ch_lazada_orders', 'ch_lazada_orders_catalog_order_id_index', 'ch_lazada_orders_sales_order_id_index'],
        ['ch_tiktok_orders', 'ch_tiktok_orders_catalog_order_id_index', 'ch_tiktok_orders_sales_order_id_index'],
        ['ch_ventacart_orders', 'ch_ventacart_orders_catalog_order_id_index', 'ch_ventacart_orders_sales_order_id_index'],
        ['ch_shopify_orders', 'ch_shopify_orders_erp_order_id_index', 'ch_shopify_orders_sales_order_id_index'],
        ['ch_shopify_order_products', 'ch_shopify_order_products_erp_order_product_id_index', 'ch_shopify_order_products_sales_order_product_id_index'],
        ['api_clients', 'api_clients_active_index', 'api_clients_enabled_index'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
        foreach (self::INDEXES as [$table, $from, $to]) {
            $this->renameIndex($table, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $from, $to]) {
            $this->renameIndex($table, $to, $from);
        }
        foreach (array_reverse(self::COLUMNS) as [$table, $from, $to]) {
            $this->renameColumn($table, $to, $from);
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
        }
    }

    private function renameIndex(string $table, string $from, string $to): void
    {
        $names = Schema::hasTable($table) ? array_column(Schema::getIndexes($table), 'name') : [];
        if (in_array($from, $names, true) && ! in_array($to, $names, true)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameIndex($from, $to));
        }
    }
};
