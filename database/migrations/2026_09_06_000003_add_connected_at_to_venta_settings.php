<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('venta_settings', 'connected_at')) {
                $t->timestamp('connected_at')->nullable()->after('last_review_push_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('venta_settings', function (Blueprint $t) {
            $t->dropColumn('connected_at');
        });
    }
};
