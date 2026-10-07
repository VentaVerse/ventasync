<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_listings', function (Blueprint $t) {
            if (! Schema::hasColumn('venta_listings', 'last_push_error')) {
                $t->string('last_push_error', 480)->nullable();
            }
            if (! Schema::hasColumn('venta_listings', 'last_push_failed_at')) {
                $t->timestamp('last_push_failed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('venta_listings', function (Blueprint $t) {
            foreach (['last_push_error', 'last_push_failed_at'] as $column) {
                if (Schema::hasColumn('venta_listings', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
