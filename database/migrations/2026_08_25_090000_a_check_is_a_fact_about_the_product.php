<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('last_push_settings');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->dropColumn('last_checked_at');
        });
    }
};
