<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate what the ERP has CHECKED from what it merely believes.
 *
 * Every status on the products page was written at push time and then left
 * alone forever. A row saying "On Venta" meant "a push succeeded at some point
 * in the past", which is a different claim from "Venta has this product", and
 * the gap between them is where the whole trap lived: a product deleted in
 * Venta's admin kept its green badge indefinitely, because nothing in the ERP
 * ever asked again.
 *
 * last_pushed_at cannot carry this. It records when we last WROTE, and the
 * useful question is when we last LOOKED. They diverge the moment anything
 * changes on the far side without us doing it, which is precisely the case
 * that was going unnoticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_product_group_products', function (Blueprint $table) {
            $table->timestamp('last_confirmed_at')->nullable()->after('last_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('venta_product_group_products', function (Blueprint $table) {
            $table->dropColumn('last_confirmed_at');
        });
    }
};
