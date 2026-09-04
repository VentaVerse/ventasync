<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-store data partitioning for Lazada (the owner, 2026-09-05).
 *
 * Shopee's data tables carried shopee_setting_id from an earlier phase, so
 * its orders and listings could be scoped per store. Lazada's did not - they
 * were keyed by `region`, so two stores in one region would collide on the
 * unique key and share rows. This adds the store key to the four
 * per-seller tables (orders, returns, listings, groups), backfills every
 * existing row to the default store, and re-aims the order/return unique
 * keys at (store, id) so each store owns its own.
 *
 * Category and brand tables stay GLOBAL: the category tree and brand list are
 * region reference data, identical for every seller, exactly as Shopee keeps
 * them. See memory: lazada-tables-lacked-store-key.
 */
return new class extends Migration {
    /**
     * The store existing rows belong to: the first enabled row, else the
     * first row of any kind. Null only when no store exists at all (a fresh
     * install with orphaned data, which cannot happen - there is no data
     * without a store to have synced it).
     */
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

        // ── Orders ──────────────────────────────────────────────
        if (Schema::hasTable('lazada_orders') && ! Schema::hasColumn('lazada_orders', 'lazada_setting_id')) {
            Schema::table('lazada_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_orders')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
            $this->swapUnique('lazada_orders', 'lazada_orders_region_order_id_unique', ['lazada_setting_id', 'order_id'], 'lazada_orders_store_order_unique');
        }

        // ── Returns (reverse orders) ────────────────────────────
        if (Schema::hasTable('lazada_reverse_orders') && ! Schema::hasColumn('lazada_reverse_orders', 'lazada_setting_id')) {
            Schema::table('lazada_reverse_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_reverse_orders')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
            $this->swapUnique('lazada_reverse_orders', 'lazada_reverse_orders_region_reverse_order_id_unique', ['lazada_setting_id', 'reverse_order_id'], 'lazada_reverse_store_id_unique');
        }

        // ── Listings ────────────────────────────────────────────
        if (Schema::hasTable('lazada_products') && ! Schema::hasColumn('lazada_products', 'lazada_setting_id')) {
            Schema::table('lazada_products', function (Blueprint $table) {
                $table->unsignedBigInteger('lazada_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('lazada_products')->whereNull('lazada_setting_id')->update(['lazada_setting_id' => $storeId]);
            }
        }

        // ── Product groups ──────────────────────────────────────
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

    /**
     * Drop the old unique index (by its conventional name, ignoring a miss so
     * a partially-applied state still converges) and add the new one.
     */
    private function swapUnique(string $table, string $oldName, array $newColumns, string $newName): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($oldName));
        } catch (\Throwable $e) {
            // Index already gone (re-run, or a fresh table that never had it).
        }
        Schema::table($table, fn (Blueprint $t) => $t->unique($newColumns, $newName));
    }
};
