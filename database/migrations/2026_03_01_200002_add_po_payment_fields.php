<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('payment_status', 16)->default('unpaid')->after('total');
            $table->decimal('amount_paid', 15, 4)->default(0)->after('payment_status');
            $table->date('payment_due_date')->nullable()->after('amount_paid');
            $table->timestamp('last_emailed_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'amount_paid', 'payment_due_date', 'last_emailed_at']);
        });
    }
};
