<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'customer_tin')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('customer_tin', 32)->default('')->after('telephone');
            });
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64);
                $table->decimal('rate', 7, 4);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_settings')) {
            Schema::create('invoice_settings', function (Blueprint $table) {
                $table->id();
                $table->string('tax_status', 16)->default('vat');
                $table->string('tin', 9)->default('');
                $table->string('branch_code', 5)->default('00000');
                $table->string('business_style', 255)->default('');
                $table->string('prefix', 24)->default('INV-');
                $table->boolean('print_logo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->integer('order_id')->unique();
                $table->string('prefix', 24);
                $table->unsignedInteger('number');
                $table->timestamp('issued_at');
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->timestamps();
                $table->unique(['prefix', 'number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_settings');
        Schema::dropIfExists('tax_rates');

        if (Schema::hasColumn('orders', 'customer_tin')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_tin');
            });
        }
    }
};
