<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('payment_terms', 64)->nullable()->after('currency_code');
            $table->boolean('email_po_enabled')->default(false)->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['payment_terms', 'email_po_enabled']);
        });
    }
};
