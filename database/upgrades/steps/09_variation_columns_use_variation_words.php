<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        ['variation_types', 'option_id', 'variation_type_id'],
        ['variation_type_descriptions', 'option_id', 'variation_type_id'],
        ['variation_values', 'option_value_id', 'variation_value_id'],
        ['variation_values', 'option_id', 'variation_type_id'],
        ['variation_value_descriptions', 'option_value_id', 'variation_value_id'],
        ['variation_value_descriptions', 'option_id', 'variation_type_id'],
        ['product_variation_types', 'product_option_id', 'product_variation_type_id'],
        ['product_variation_types', 'option_id', 'variation_type_id'],
        ['product_variation_values', 'product_option_value_id', 'product_variation_value_id'],
        ['product_variation_values', 'product_option_id', 'product_variation_type_id'],
        ['product_variation_values', 'option_id', 'variation_type_id'],
        ['product_variation_values', 'option_value_id', 'variation_value_id'],
        ['product_variation_parts', 'combination_id', 'product_variation_id'],
        ['product_variation_parts', 'product_option_value_id', 'product_variation_value_id'],
        ['order_options', 'product_option_id', 'product_variation_type_id'],
        ['order_options', 'product_option_value_id', 'product_variation_value_id'],
        ['ch_lazada_listing_variations', 'product_option_value_id', 'product_variation_value_id'],
        ['ch_woocommerce_listing_links', 'product_option_value_id', 'product_variation_value_id'],
        ['ch_woocommerce_order_products', 'product_option_value_id', 'product_variation_value_id'],
        ['product_suppliers', 'product_option_value_id', 'product_variation_value_id'],
        ['purchase_order_items', 'product_option_value_id', 'product_variation_value_id'],
        ['stock_history', 'product_option_value_id', 'product_variation_value_id'],
        ['warehouse_inventory', 'product_option_value_id', 'product_variation_value_id'],
        ['warehouse_transfer_items', 'product_option_value_id', 'product_variation_value_id'],
    ];

    private const PERMISSIONS = [
        ['view_catalog/product_option', 'view_catalog/product_variation'],
        ['manage_catalog/product_option', 'manage_catalog/product_variation'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
        foreach (self::PERMISSIONS as [$from, $to]) {
            $this->movePermission($from, $to);
        }
        DB::table('activity_logs')->where('subject_type', 'Product Options')->update(['subject_type' => 'Product Variations']);
    }

    public function down(): void
    {
        DB::table('activity_logs')->where('subject_type', 'Product Variations')->update(['subject_type' => 'Product Options']);
        foreach (array_reverse(self::PERMISSIONS) as [$from, $to]) {
            $this->movePermission($to, $from);
        }
        foreach (array_reverse(self::COLUMNS) as [$table, $from, $to]) {
            $this->renameColumn($table, $to, $from);
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            \App\Support\Database\ColumnRename::run($table, $from, $to);
        }
    }

    private function movePermission(string $from, string $to): void
    {
        $old = DB::table('permissions')->where('key', $from)->value('id');
        if ($old === null) {
            return;
        }
        $new = DB::table('permissions')->where('key', $to)->value('id');
        if ($new === null) {
            DB::table('permissions')->where('id', $old)->update(['key' => $to]);

            return;
        }
        foreach (DB::table('user_group_permissions')->where('permission_id', $old)->pluck('user_group_id') as $group) {
            DB::table('user_group_permissions')->insertOrIgnore(['user_group_id' => $group, 'permission_id' => $new]);
        }
        DB::table('user_group_permissions')->where('permission_id', $old)->delete();
        DB::table('permissions')->where('id', $old)->delete();
    }
};
