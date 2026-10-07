<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('id');
            $table->boolean('enabled')->default(true)->after('store_name');
        });

        DB::table('shopee_settings')->whereNull('store_name')
            ->orderBy('id')->limit(1)->update(['store_name' => 'Main store']);
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            $table->dropColumn(['store_name', 'enabled']);
        });
    }
};
