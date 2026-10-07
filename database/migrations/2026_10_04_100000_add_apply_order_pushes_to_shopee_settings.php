<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_settings', 'apply_order_pushes')) {
                $table->boolean('apply_order_pushes')->default(false)->after('push_partner_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            if (Schema::hasColumn('shopee_settings', 'apply_order_pushes')) {
                $table->dropColumn('apply_order_pushes');
            }
        });
    }
};
