<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Shopee half of the belief-versus-fact split Venta got first.
 *
 * Every status on the Shopee products page was written at push time and never
 * revisited, so "Pushed" meant "a push once succeeded" while reading as a
 * claim about right now. Delete the item in Seller Centre and the green badge
 * stayed indefinitely, because nothing ever asked again.
 *
 * last_pushed_at cannot carry this. It records when we last WROTE; the useful
 * question is when we last LOOKED, and the two diverge exactly when something
 * changed on Shopee without us doing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_product_group_products', function (Blueprint $table) {
            $table->timestamp('last_confirmed_at')->nullable()->after('last_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_product_group_products', function (Blueprint $table) {
            $table->dropColumn('last_confirmed_at');
        });
    }
};
