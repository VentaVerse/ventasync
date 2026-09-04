<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-store data partitioning for TikTok (the owner, 2026-09-05), the same shape
 * Lazada got in add_store_key_to_lazada_data_tables. TikTok's data tables
 * carried no store key - orders were unique on (region, order_id), and a
 * listing was unique on product_id ALONE, meaning a catalog product could be
 * listed on exactly one store. This adds tiktok_setting_id to the four
 * per-seller tables, backfills every row to the default store, and re-aims the
 * unique keys at the store so each store owns its own orders, returns and
 * listings. Category/brand tables stay GLOBAL (region reference data). See
 * memory lazada-tables-lacked-store-key.
 */
return new class extends Migration {
    private function defaultStoreId(): ?int
    {
        if (! Schema::hasTable('tiktok_settings')) {
            return null;
        }

        return DB::table('tiktok_settings')->where('enabled', true)->orderBy('id')->value('id')
            ?? DB::table('tiktok_settings')->orderBy('id')->value('id');
    }

    public function up(): void
    {
        $storeId = $this->defaultStoreId();

        // ── Orders ──────────────────────────────────────────────
        if (Schema::hasTable('tiktok_orders') && ! Schema::hasColumn('tiktok_orders', 'tiktok_setting_id')) {
            Schema::table('tiktok_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_orders')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            $this->swapUnique('tiktok_orders', 'tiktok_orders_region_order_id_unique', ['tiktok_setting_id', 'order_id'], 'tiktok_orders_store_order_unique');
        }

        // ── Returns ─────────────────────────────────────────────
        if (Schema::hasTable('tiktok_returns') && ! Schema::hasColumn('tiktok_returns', 'tiktok_setting_id')) {
            Schema::table('tiktok_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_returns')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            // return_id was unique on its own (TikTok return ids are globally
            // unique); the store key joins it so two stores can never contend.
            $this->swapUnique('tiktok_returns', 'tiktok_returns_return_id_unique', ['tiktok_setting_id', 'return_id'], 'tiktok_returns_store_return_unique');
        }

        // ── Listings ────────────────────────────────────────────
        if (Schema::hasTable('tiktok_listings') && ! Schema::hasColumn('tiktok_listings', 'tiktok_setting_id')) {
            Schema::table('tiktok_listings', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_listings')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            // product_id was unique ALONE - a product could list on one store
            // only. It becomes (store, product_id) so the same catalog product
            // can carry a distinct listing per store.
            $this->swapUnique('tiktok_listings', 'tiktok_listings_product_id_unique', ['tiktok_setting_id', 'product_id'], 'tiktok_listings_store_product_unique');
        }

        // ── Product groups ──────────────────────────────────────
        if (Schema::hasTable('tiktok_product_groups') && ! Schema::hasColumn('tiktok_product_groups', 'tiktok_setting_id')) {
            Schema::table('tiktok_product_groups', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_product_groups')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tiktok_orders') && Schema::hasColumn('tiktok_orders', 'tiktok_setting_id')) {
            $this->swapUnique('tiktok_orders', 'tiktok_orders_store_order_unique', ['region', 'order_id'], 'tiktok_orders_region_order_id_unique');
            Schema::table('tiktok_orders', fn (Blueprint $t) => $t->dropColumn('tiktok_setting_id'));
        }
        if (Schema::hasTable('tiktok_returns') && Schema::hasColumn('tiktok_returns', 'tiktok_setting_id')) {
            $this->swapUnique('tiktok_returns', 'tiktok_returns_store_return_unique', ['return_id'], 'tiktok_returns_return_id_unique');
            Schema::table('tiktok_returns', fn (Blueprint $t) => $t->dropColumn('tiktok_setting_id'));
        }
        if (Schema::hasTable('tiktok_listings') && Schema::hasColumn('tiktok_listings', 'tiktok_setting_id')) {
            $this->swapUnique('tiktok_listings', 'tiktok_listings_store_product_unique', ['product_id'], 'tiktok_listings_product_id_unique');
            Schema::table('tiktok_listings', fn (Blueprint $t) => $t->dropColumn('tiktok_setting_id'));
        }
        if (Schema::hasTable('tiktok_product_groups') && Schema::hasColumn('tiktok_product_groups', 'tiktok_setting_id')) {
            Schema::table('tiktok_product_groups', fn (Blueprint $t) => $t->dropColumn('tiktok_setting_id'));
        }
    }

    /**
     * Drop the old unique index (by its conventional name, tolerating a miss)
     * and add the new one.
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
