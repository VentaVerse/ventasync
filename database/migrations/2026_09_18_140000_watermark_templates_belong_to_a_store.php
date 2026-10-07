<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STORE_TABLES = [
        'shopee' => 'shopee_settings',
        'lazada' => 'lazada_settings',
        'tiktok' => 'tiktok_settings',
        'venta' => 'venta_settings',
        'woocommerce' => 'woocommerce_settings',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('watermark_templates')) {
            return;
        }

        $existing = DB::table('watermark_templates')->get();

        Schema::table('watermark_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('watermark_templates', 'integration')) {
                $table->string('integration', 40)->default('')->after('id');
            }
            if (! Schema::hasColumn('watermark_templates', 'store_id')) {
                $table->unsignedBigInteger('store_id')->default(0)->after('integration');
            }
        });

        $this->dropNameUnique();

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->unique(['integration', 'store_id', 'name'], 'watermark_templates_store_name_unique');
        });

        if ($existing->isNotEmpty()) {
            $this->handDownToEveryStore($existing);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('watermark_templates')) {
            return;
        }

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->dropUnique('watermark_templates_store_name_unique');
        });

        $keep = DB::table('watermark_templates')->orderBy('id')->get()->unique('name')->pluck('id')->all();
        DB::table('watermark_templates')->whereNotIn('id', $keep ?: [0])->delete();

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->unique('name');
            $table->dropColumn(['integration', 'store_id']);
        });
    }

    private function handDownToEveryStore(\Illuminate\Support\Collection $existing): void
    {
        $stores = [];
        foreach (self::STORE_TABLES as $integration => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->pluck('id') as $id) {
                $stores[] = [$integration, (int) $id];
            }
        }

        foreach ($existing as $row) {
            $columns = array_diff_key((array) $row, array_flip(['id', 'integration', 'store_id']));
            foreach ($stores as [$integration, $storeId]) {
                DB::table('watermark_templates')->insert($columns + [
                    'integration' => $integration,
                    'store_id' => $storeId,
                ]);
            }
            DB::table('watermark_templates')->where('id', $row->id)->delete();
        }
    }

    private function dropNameUnique(): void
    {
        try {
            Schema::table('watermark_templates', function (Blueprint $table) {
                $table->dropUnique('watermark_templates_name_unique');
            });
        } catch (\Throwable) {
        }
    }
};
