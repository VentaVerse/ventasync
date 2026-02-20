<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('opencart_settings', 'admin_secret')) {
            return;
        }

        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->string('admin_secret', 255)->default('')->after('admin_path');
        });
    }

    public function down(): void
    {
        Schema::table('opencart_settings', function (Blueprint $table) {
            $table->dropColumn('admin_secret');
        });
    }
};
