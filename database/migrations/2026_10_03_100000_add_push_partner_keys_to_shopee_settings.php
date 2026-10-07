<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_settings', 'push_partner_key')) {
                $table->text('push_partner_key')->nullable()->after('partner_key');
            }
            if (! Schema::hasColumn('shopee_settings', 'sandbox_push_partner_key')) {
                $table->text('sandbox_push_partner_key')->nullable()->after('sandbox_partner_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_settings', function (Blueprint $table) {
            foreach (['push_partner_key', 'sandbox_push_partner_key'] as $column) {
                if (Schema::hasColumn('shopee_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
