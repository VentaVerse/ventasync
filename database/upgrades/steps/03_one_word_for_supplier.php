<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        ['vendors', 'suppliers'],
        ['product_vendors', 'product_suppliers'],
    ];

    private const COLUMNS = [
        ['product_suppliers', 'vendor_id', 'supplier_id'],
        ['product_suppliers', 'vendor_sku', 'supplier_sku'],
        ['purchase_orders', 'vendor_id', 'supplier_id'],
        ['purchase_order_items', 'vendor_sku', 'supplier_sku'],
        ['supplier_invoices', 'vendor_id', 'supplier_id'],
    ];

    private const PERMISSIONS = [
        ['view_purchasing/vendor', 'view_purchasing/supplier'],
        ['manage_purchasing/vendor', 'manage_purchasing/supplier'],
        ['view_purchasing/product_vendor', 'view_purchasing/product_supplier'],
        ['manage_purchasing/product_vendor', 'manage_purchasing/product_supplier'],
    ];

    private const LOG_SUBJECTS = [
        ['Vendor', 'Supplier'],
        ['Product Vendor', 'Product Supplier'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as [$from, $to]) {
            $this->renameTable($from, $to);
        }
        foreach (self::COLUMNS as [$table, $from, $to]) {
            $this->renameColumn($table, $from, $to);
        }
        foreach (self::PERMISSIONS as [$from, $to]) {
            $this->movePermission($from, $to);
        }
        foreach (self::LOG_SUBJECTS as [$from, $to]) {
            DB::table('activity_logs')->where('subject_type', $from)->update(['subject_type' => $to]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::LOG_SUBJECTS) as [$from, $to]) {
            DB::table('activity_logs')->where('subject_type', $to)->update(['subject_type' => $from]);
        }
        foreach (array_reverse(self::PERMISSIONS) as [$from, $to]) {
            $this->movePermission($to, $from);
        }
        foreach (array_reverse(self::COLUMNS) as [$table, $from, $to]) {
            $this->renameColumn($this->tableNow($table), $to, $from);
        }
        foreach (array_reverse(self::TABLES) as [$from, $to]) {
            $this->renameTable($to, $from);
        }
    }

    private function tableNow(string $table): string
    {
        foreach (self::TABLES as [$from, $to]) {
            if ($table === $to && ! Schema::hasTable($to) && Schema::hasTable($from)) {
                return $from;
            }
        }

        return $table;
    }

    private function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
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
        $groups = DB::table('user_group_permissions')->where('permission_id', $old)->pluck('user_group_id');
        foreach ($groups as $group) {
            DB::table('user_group_permissions')->insertOrIgnore(['user_group_id' => $group, 'permission_id' => $new]);
        }
        DB::table('user_group_permissions')->where('permission_id', $old)->delete();
        DB::table('permissions')->where('id', $old)->delete();
    }
};
