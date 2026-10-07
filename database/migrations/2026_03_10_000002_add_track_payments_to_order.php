<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('catalog.prefix', '') . 'order';
        Schema::table($table, function (Blueprint $t) {
            $t->boolean('track_payments')->default(false)->after('marketplace_order_id');
        });
    }

    public function down(): void
    {
        $table = config('catalog.prefix', '') . 'order';
        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('track_payments');
        });
    }
};
