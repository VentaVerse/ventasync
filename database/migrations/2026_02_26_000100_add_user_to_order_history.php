<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $p = config('catalog.prefix');

        Schema::table($p . 'order_history', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('order_status_id');
            $table->string('user_name', 100)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        $p = config('catalog.prefix');

        Schema::table($p . 'order_history', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'user_name']);
        });
    }
};
