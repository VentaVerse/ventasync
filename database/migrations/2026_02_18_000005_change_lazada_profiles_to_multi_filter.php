<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Add new JSON columns
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->json('catalog_category_ids')->nullable()->after('name');
            $table->json('manufacturer_ids')->nullable()->after('catalog_category_ids');
        });

        // Migrate existing single values into JSON arrays
        $profiles = DB::table('lazada_profiles')->get();
        foreach ($profiles as $p) {
            $catIds = $p->catalog_category_id ? json_encode([(int) $p->catalog_category_id]) : null;
            $mfgIds = $p->manufacturer_id ? json_encode([(int) $p->manufacturer_id]) : null;
            DB::table('lazada_profiles')->where('id', $p->id)->update([
                'catalog_category_ids' => $catIds,
                'manufacturer_ids' => $mfgIds,
            ]);
        }

        // Drop old single-value columns
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->dropColumn(['catalog_category_id', 'manufacturer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->unsignedInteger('catalog_category_id')->nullable()->after('name');
            $table->unsignedInteger('manufacturer_id')->nullable()->after('catalog_category_id');
        });

        // Migrate first value back
        $profiles = DB::table('lazada_profiles')->get();
        foreach ($profiles as $p) {
            $catIds = $p->catalog_category_ids ? json_decode($p->catalog_category_ids, true) : [];
            $mfgIds = $p->manufacturer_ids ? json_decode($p->manufacturer_ids, true) : [];
            DB::table('lazada_profiles')->where('id', $p->id)->update([
                'catalog_category_id' => $catIds[0] ?? null,
                'manufacturer_id' => $mfgIds[0] ?? null,
            ]);
        }

        Schema::table('lazada_profiles', function (Blueprint $table) {
            $table->dropColumn(['catalog_category_ids', 'manufacturer_ids']);
        });
    }
};
