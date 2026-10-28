<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        ['orders', 'marketplace_order_id', 'channel_order_id'],
        ['order_products', 'model', 'sku'],
        ['products', 'subtract', 'subtract_stock'],
        ['product_variation_values', 'subtract', 'subtract_stock'],
        ['product_variations', 'subtract', 'subtract_stock'],
        ['ch_marketplace_reviews', 'oc_sync_status', 'opencart_sync_status'],
        ['ch_marketplace_reviews', 'oc_review_id', 'opencart_review_id'],
        ['ch_marketplace_reviews', 'oc_pushed_at', 'opencart_pushed_at'],
        ['ch_marketplace_reviews', 'oc_push_error', 'opencart_push_error'],
    ];

    private const INDEXES = [
        ['orders', 'orders_marketplace_order_id_index', 'orders_channel_order_id_index'],
        ['ch_marketplace_reviews', 'ch_marketplace_reviews_oc_sync_status_index', 'ch_marketplace_reviews_opencart_sync_status_index'],
    ];

    private const FEE_CODES = [
        ['marketplace_commission', 'channel_commission'],
        ['marketplace_payment_fee', 'channel_payment_fee'],
        ['marketplace_shipping_fee', 'channel_shipping_fee'],
        ['marketplace_other_fee', 'channel_other_fee'],
        ['marketplace_voucher', 'channel_voucher'],
    ];

    private const PERMISSIONS = [
        ['view_settings/fulfilment', 'view_settings/fulfillment'],
        ['manage_settings/fulfilment', 'manage_settings/fulfillment'],
    ];

    private const DROPPED_COLUMNS = [
        'products' => [
            'model', 'location', 'stock_status_id', 'tax_class_id', 'weight_class_id', 'length_class_id', 'shipping',
            'minimum', 'points', 'viewed', 'date_available', 'sort_order', 'config_background_image',
            'config_background_image_position', 'add_cart', 'product_additionals', 'meta_robots', 'seo_canonical',
        ],
        'orders' => [
            'fax', 'custom_field', 'payment_custom_field', 'shipping_custom_field', 'payment_address_format',
            'shipping_address_format', 'affiliate_id', 'marketing_id', 'commission', 'tracking', 'forwarded_ip',
            'user_agent', 'accept_language', 'oe_import', 'invoice_prefix', 'invoice_no', 'store_url', 'customer_id',
            'customer_group_id', 'payment_country_id', 'payment_zone_id', 'shipping_country_id', 'shipping_zone_id',
            'payment_code', 'shipping_code', 'language_id',
        ],
        'order_products' => ['reward', 'tax'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
        foreach (self::INDEXES as [$table, $from, $to]) {
            $this->renameIndex($table, $from, $to);
        }

        foreach (self::FEE_CODES as [$from, $to]) {
            DB::table('order_totals')->where('code', $from)->update(['code' => $to]);
        }
        foreach (self::PERMISSIONS as [$from, $to]) {
            $this->movePermission($from, $to);
        }

        foreach (self::DROPPED_COLUMNS as $table => $columns) {
            $present = Schema::hasTable($table) ? array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c))) : [];
            if ($present !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }

    // The dropped OpenCart columns are not brought back: nothing reads them.
    public function down(): void
    {
        foreach (array_reverse(self::PERMISSIONS) as [$from, $to]) {
            $this->movePermission($to, $from);
        }
        foreach (self::FEE_CODES as [$from, $to]) {
            DB::table('order_totals')->where('code', $to)->update(['code' => $from]);
        }
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
            \App\Support\Database\ColumnRename::run($table, $from, $to);
        }
    }

    private function renameIndex(string $table, string $from, string $to): void
    {
        $names = Schema::hasTable($table) ? array_column(Schema::getIndexes($table), 'name') : [];
        if (in_array($from, $names, true) && ! in_array($to, $names, true)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameIndex($from, $to));
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
