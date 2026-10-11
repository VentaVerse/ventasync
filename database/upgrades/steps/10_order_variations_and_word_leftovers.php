<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STORE_LOG_TYPES = [
        'lazada_settings' => 'Lazada Store',
        'opencart_settings' => 'OpenCart Store',
        'shopee_settings' => 'Shopee Store',
        'shopify_settings' => 'Shopify Store',
        'tiktok_settings' => 'TikTok Store',
        'ventacart_settings' => 'VentaCart Store',
        'woocommerce_settings' => 'WooCommerce Store',
    ];

    public function up(): void
    {
        if (Schema::hasTable('order_options') && ! Schema::hasTable('order_variations')) {
            Schema::rename('order_options', 'order_variations');
        }
        if (Schema::hasColumn('order_variations', 'order_option_id')) {
            Schema::table('order_variations', fn (Blueprint $t) => $t->renameColumn('order_option_id', 'order_variation_id'));
        }
        DB::table('order_variations')->where('name', 'Option')->update(['name' => 'Variation']);

        foreach (self::STORE_LOG_TYPES as $from => $to) {
            DB::table('activity_logs')->where('subject_type', $from)->update(['subject_type' => $to]);
        }
        if (Schema::hasTable('ch_opencart_sync_log')) {
            DB::table('ch_opencart_sync_log')->where('entity_type', 'option')->update(['entity_type' => 'variation']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ch_opencart_sync_log')) {
            DB::table('ch_opencart_sync_log')->where('entity_type', 'variation')->update(['entity_type' => 'option']);
        }
        foreach (self::STORE_LOG_TYPES as $from => $to) {
            DB::table('activity_logs')->where('subject_type', $to)->where('action', 'like', '%.store.deleted')->update(['subject_type' => $from]);
        }
        DB::table('order_variations')->where('name', 'Variation')->update(['name' => 'Option']);
        if (Schema::hasColumn('order_variations', 'order_variation_id')) {
            Schema::table('order_variations', fn (Blueprint $t) => $t->renameColumn('order_variation_id', 'order_option_id'));
        }
        if (Schema::hasTable('order_variations') && ! Schema::hasTable('order_options')) {
            Schema::rename('order_variations', 'order_options');
        }
    }
};
