<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'shopee_orders' => ['shopee_orders_region_order_sn_unique', ['shopee_setting_id', 'order_sn'], 'shopee_orders_store_order_unique', []],
        'shopee_returns' => ['shopee_returns_region_return_sn_unique', ['shopee_setting_id', 'return_sn'], 'shopee_returns_store_return_unique', ['return_sn']],
        'shopee_product_links' => ['spl_unique', ['shopee_setting_id', 'product_id', 'shopee_item_id', 'shopee_model_id'], 'spl_store_unique', ['product_id', 'shopee_item_id']],
        'shopee_listings' => ['shopee_listings_product_id_unique', ['shopee_setting_id', 'product_id'], 'shopee_listings_store_product_unique', ['product_id']],
        'shopee_product_groups' => [null, null, null, []],
        'shopee_item_cache' => ['sic_unique', ['shopee_setting_id', 'shopee_item_id', 'shopee_model_id'], 'sic_store_unique', ['shopee_item_id']],
        'shopee_unmatched_items' => ['sui_unique', ['shopee_setting_id', 'shopee_item_id', 'shopee_model_id'], 'sui_store_unique', ['shopee_item_id']],
        'shopee_logistics' => ['shopee_logistics_logistics_channel_id_unique', ['shopee_setting_id', 'logistics_channel_id'], 'shopee_logistics_store_channel_unique', []],
    ];

    public function up(): void
    {
        $storeId = DB::table('shopee_settings')->orderBy('id')->value('id');
        if ($storeId === null) {
            foreach (array_keys(self::TABLES) as $t) {
                if (DB::table($t)->exists()) {
                    $storeId = DB::table('shopee_settings')->insertGetId([
                        'store_name' => 'Main store', 'mode' => 'live',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    break;
                }
            }
        }

        foreach (self::TABLES as $table => [$dropUnique, $uniqueCols, $uniqueName, $indexes]) {
            if (! Schema::hasColumn($table, 'shopee_setting_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('shopee_setting_id')->nullable()->after('id');
                });
            }
            if ($storeId !== null) {
                DB::table($table)->whereNull('shopee_setting_id')->update(['shopee_setting_id' => $storeId]);
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('shopee_setting_id')->nullable(false)->change();
            });
            if ($dropUnique !== null && $this->indexExists($table, $dropUnique)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($dropUnique));
            }
            if ($uniqueCols !== null && ! $this->indexExists($table, $uniqueName)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($uniqueCols, $uniqueName));
            }
            if ($uniqueCols === null && ! $this->indexExists($table, $table . '_shopee_setting_id_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->index('shopee_setting_id'));
            }
            foreach ($indexes as $col) {
                if (! $this->indexExists($table, $table . '_' . $col . '_index')) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($col));
                }
            }
            if (! $this->foreignExists($table)) {
                Schema::table($table, fn (Blueprint $t) => $t->foreign('shopee_setting_id')->references('id')->on('shopee_settings')->restrictOnDelete());
            }
        }

        if (! Schema::hasColumn('shopee_api_logs', 'shopee_setting_id')) {
            Schema::table('shopee_api_logs', function (Blueprint $t) {
                $t->unsignedBigInteger('shopee_setting_id')->nullable()->after('id')->index();
            });
        }
        if ($storeId !== null) {
            DB::table('shopee_api_logs')->whereNull('shopee_setting_id')->update(['shopee_setting_id' => $storeId]);
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($i) => $i->Key_name === $name);
    }

    private function foreignExists(string $table): bool
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, 'shopee_setting_id']
        ))->isNotEmpty();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => [$dropUnique, $uniqueCols, $uniqueName, $indexes]) {
            Schema::table($table, function (Blueprint $t) use ($table, $dropUnique, $uniqueCols, $uniqueName, $indexes) {
                $t->dropForeign([$table === '' ? '' : 'shopee_setting_id']);
                if ($uniqueName !== null) {
                    $t->dropUnique($uniqueName);
                }
                foreach ($indexes as $col) {
                    $t->dropIndex([$col]);
                }
            });
            if ($dropUnique !== null) {
                Schema::table($table, function (Blueprint $t) use ($dropUnique) {
                    match ($dropUnique) {
                        'shopee_orders_region_order_sn_unique' => $t->unique(['region', 'order_sn'], $dropUnique),
                        'shopee_returns_region_return_sn_unique' => $t->unique(['region', 'return_sn'], $dropUnique),
                        'spl_unique' => $t->unique(['product_id', 'shopee_item_id', 'shopee_model_id'], $dropUnique),
                        'shopee_listings_product_id_unique' => $t->unique(['product_id'], $dropUnique),
                        'sic_unique' => $t->unique(['shopee_item_id', 'shopee_model_id'], $dropUnique),
                        'sui_unique' => $t->unique(['shopee_item_id', 'shopee_model_id'], $dropUnique),
                        'shopee_logistics_logistics_channel_id_unique' => $t->unique(['logistics_channel_id'], $dropUnique),
                        default => null,
                    };
                });
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('shopee_setting_id');
            });
        }

        Schema::table('shopee_api_logs', function (Blueprint $t) {
            $t->dropColumn('shopee_setting_id');
        });
    }
};
