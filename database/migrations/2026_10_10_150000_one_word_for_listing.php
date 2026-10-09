<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        ['lazada_products', 'lazada_listings'],
        ['lazada_product_variants', 'lazada_listing_variations'],
        ['lazada_product_attributes', 'lazada_listing_attributes'],
        ['shopee_product_links', 'shopee_listing_links'],
        ['ventacart_product_links', 'ventacart_listing_links'],
        ['woocommerce_product_links', 'woocommerce_listing_links'],
        ['opencart_product_links', 'opencart_listing_links'],
        ['shopify_product_links', 'shopify_listing_links'],
    ];

    private const COLUMNS = [
        ['lazada_listing_variations', 'lazada_product_id', 'lazada_listing_id'],
        ['lazada_listing_attributes', 'lazada_product_id', 'lazada_listing_id'],
        ['lazada_product_group_products', 'lazada_product_id', 'lazada_listing_id'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$from, $to]) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
        foreach (self::COLUMNS as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::COLUMNS) as [$table, $from, $to]) {
            $this->renameColumn($table, $to, $from);
        }
        foreach (array_reverse(self::TABLES) as [$from, $to]) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
        }
    }
};
