<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->timestamp('unlinked_at')->nullable()->after('lazada_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropColumn('unlinked_at');
        });
    }
};
