<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendors', 'email_po_enabled')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('email_po_enabled');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendors', 'email_po_enabled')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->boolean('email_po_enabled')->default(false)->after('payment_terms');
            });
        }
    }
};
