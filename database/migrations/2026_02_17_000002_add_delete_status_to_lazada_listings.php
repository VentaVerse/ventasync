<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (!Schema::hasColumn('lazada_products', 'lazada_deleted_at')) {
                $table->timestamp('lazada_deleted_at')->nullable()->after('lazada_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_products', 'lazada_deleted_at')) {
                $table->dropColumn('lazada_deleted_at');
            }
        });
    }
};
