<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'packing_check')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('packing_check')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'packing_check')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('packing_check');
            });
        }
    }
};
