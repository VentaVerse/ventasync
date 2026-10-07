<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function defaultStoreId(): ?int
    {
        if (! Schema::hasTable('lazada_settings')) {
            return null;
        }

        return DB::table('lazada_settings')->where('enabled', true)->orderBy('id')->value('id')
            ?? DB::table('lazada_settings')->orderBy('id')->value('id');
    }

    public function up(): void
    {
        $storeId = $this->defaultStoreId();

        if (Schema::hasTable('lazada_orders') && ! Schema::hasColumn('lazada_orders', 'lazada_setting_id')) {
            Schema::table('lazada_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_orders')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
            $this->swapUnique('lazada_orders', 'lazada_orders_region_order_id_unique', ['lazada_setting_id', 'order_id'], 'lazada_orders_store_order_unique');
        }

        if (Schema::hasTable('lazada_reverse_orders') && ! Schema::hasColumn('lazada_reverse_orders', 'lazada_setting_id')) {
            Schema::table('lazada_reverse_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_reverse_orders')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
            $this->swapUnique('lazada_reverse_orders', 'lazada_reverse_orders_region_reverse_order_id_unique', ['lazada_setting_id', 'reverse_order_id'], 'lazada_reverse_store_id_unique');
        }

        if (Schema::hasTable('lazada_products') && ! Schema::hasColumn('lazada_products', 'lazada_setting_id')) {
            Schema::table('lazada_products', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_products')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
        }

        if (Schema::hasTable('lazada_product_groups') && ! Schema::hasColumn('lazada_product_groups', 'lazada_setting_id')) {
            Schema::table('lazada_product_groups', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_product_groups')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lazada_orders') && Schema::hasColumn('lazada_orders', 'lazada_setting_id')) {
            $this->swapUnique('lazada_orders', 'lazada_orders_store_order_unique', ['region', 'order_id'], 'lazada_orders_region_order_id_unique');
            Schema::table('lazada_orders', fn (Blueprint $t) => $t->dropColumn('lazada_setting_id'));
        }
        if (Schema::hasTable('lazada_reverse_orders') && Schema::hasColumn('lazada_reverse_orders', 'lazada_setting_id')) {
            $this->swapUnique('lazada_reverse_orders', 'lazada_reverse_store_id_unique', ['region', 'reverse_order_id'], 'lazada_reverse_orders_region_reverse_order_id_unique');
            Schema::table('lazada_reverse_orders', fn (Blueprint $t) => $t->dropColumn('lazada_setting_id'));
        }
        foreach (['lazada_products', 'lazada_product_groups'] as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'lazada_setting_id')) {
                Schema::table($tbl, fn (Blueprint $t) => $t->dropColumn('lazada_setting_id'));
            }
        }
    }

    private function swapUnique(string $table, string $oldName, array $newColumns, string $newName): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($oldName));
        } catch (\Throwable $e) {
        }
        Schema::table($table, fn (Blueprint $t) => $t->unique($newColumns, $newName));
    }
};
