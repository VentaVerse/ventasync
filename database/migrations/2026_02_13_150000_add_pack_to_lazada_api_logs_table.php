<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_api_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('lazada_api_logs', 'pack')) {
                $table->string('pack', 64)->nullable()->after('id');
                $table->index(['pack', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_api_logs', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_api_logs', 'pack')) {
                $table->dropIndex(['pack', 'created_at']);
                $table->dropColumn('pack');
            }
        });
    }
};
