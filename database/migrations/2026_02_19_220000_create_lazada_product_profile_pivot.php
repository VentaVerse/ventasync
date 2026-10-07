<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lazada_product_profile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lazada_product_id');
            $table->unsignedBigInteger('lazada_profile_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['lazada_product_id', 'lazada_profile_id'], 'lpp_product_profile_unique');

            $table->foreign('lazada_product_id')
                ->references('id')->on('lazada_products')
                ->cascadeOnDelete();

            $table->foreign('lazada_profile_id')
                ->references('id')->on('lazada_profiles')
                ->cascadeOnDelete();
        });

        DB::statement('
            INSERT INTO lazada_product_profile (lazada_product_id, lazada_profile_id)
            SELECT id, lazada_profile_id
            FROM lazada_products
            WHERE lazada_profile_id IS NOT NULL
        ');

        Schema::table('lazada_products', function (Blueprint $table) {
            $fks = collect(DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'lazada_products'
                  AND COLUMN_NAME = 'lazada_profile_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            "));

            foreach ($fks as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            $table->dropColumn('lazada_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->unsignedBigInteger('lazada_profile_id')->nullable()->after('product_id');
            $table->foreign('lazada_profile_id')
                ->references('id')->on('lazada_profiles')
                ->nullOnDelete();
        });

        $rows = DB::table('lazada_product_profile')
            ->select('lazada_product_id', DB::raw('MIN(lazada_profile_id) as lazada_profile_id'))
            ->groupBy('lazada_product_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('lazada_products')
                ->where('id', $row->lazada_product_id)
                ->update(['lazada_profile_id' => $row->lazada_profile_id]);
        }

        Schema::dropIfExists('lazada_product_profile');
    }
};
