<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        if (Schema::hasTable('tiktok_orders') && ! Schema::hasColumn('tiktok_orders', 'tiktok_setting_id')) {
            Schema::table('tiktok_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_orders')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            $this->swapUnique('tiktok_orders', 'tiktok_orders_region_order_id_unique', ['tiktok_setting_id', 'order_id'], 'tiktok_orders_store_order_unique');
        }

        if (Schema::hasTable('tiktok_returns') && ! Schema::hasColumn('tiktok_returns', 'tiktok_setting_id')) {
            Schema::table('tiktok_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_returns')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            $this->swapUnique('tiktok_returns', 'tiktok_returns_return_id_unique', ['tiktok_setting_id', 'return_id'], 'tiktok_returns_store_return_unique');
        }

        if (Schema::hasTable('tiktok_listings') && ! Schema::hasColumn('tiktok_listings', 'tiktok_setting_id')) {
            Schema::table('tiktok_listings', function (Blueprint $table) {
                $table->unsignedBigInteger('tiktok_setting_id')->nullable()->after('id')->index();
            });
            if ($storeId !== null) {
                DB::table('tiktok_listings')->whereNull('tiktok_setting_id')->update(['tiktok_setting_id' => $storeId]);
            }
            $this->swapUnique('tiktok_listings', 'tiktok_listings_product_id_unique', ['tiktok_setting_id', 'product_id'], 'tiktok_listings_store_product_unique');
        }

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

    private function swapUnique(string $table, string $oldName, array $newColumns, string $newName): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($oldName));
        } catch (\Throwable $e) {
        }
        Schema::table($table, fn (Blueprint $t) => $t->unique($newColumns, $newName));
    }
};
