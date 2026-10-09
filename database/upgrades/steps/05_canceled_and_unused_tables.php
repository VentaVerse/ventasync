<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DROPPED_TABLES = ['supplier_invoice_payments', 'supplier_invoice_items', 'supplier_invoices', 'module_licenses'];

    private const DROPPED_COLUMNS = [
        'lazada_settings' => ['im_app_key', 'im_app_secret', 'im_auth_code', 'im_access_token', 'im_refresh_token',
            'im_expires_at', 'im_refresh_expires_at', 'im_enabled', 'im_last_chat_push_at'],
        'shopee_settings' => ['messaging_enabled', 'last_chat_push_at'],
    ];

    private function spellings(): array
    {
        $pfx = (string) config('catalog.prefix');

        return [
            ['purchase_orders', 'status', 'cancelled', 'canceled'],
            ['warehouse_transfers', 'status', 'cancelled', 'canceled'],
            [$pfx . 'order_status', 'name', 'Cancelled', 'Canceled'],
            ['lazada_orders', 'payout_status', 'Cancelled', 'Canceled'],
            ['shopee_orders', 'payout_status', 'Cancelled', 'Canceled'],
            ['tiktok_orders', 'payout_status', 'Cancelled', 'Canceled'],
            ['activity_logs', 'action', 'cancelled', 'canceled'],
        ];
    }

    public function up(): void
    {
        foreach ($this->spellings() as [$table, $column, $from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
        foreach (self::DROPPED_TABLES as $table) {
            Schema::dropIfExists($table);
        }
        foreach (self::DROPPED_COLUMNS as $table => $columns) {
            $present = Schema::hasTable($table) ? array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c))) : [];
            if ($present !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->spellings() as [$table, $column, $from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $to)->update([$column => $from]);
            }
        }
    }
};
