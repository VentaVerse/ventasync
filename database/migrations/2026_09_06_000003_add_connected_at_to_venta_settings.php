<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The Venta Connection tab gains the connect flow every other channel has
 * (2026-09-06). Its "Check the connection" step needs to remember that the
 * store answered, which is what this column holds. */
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
