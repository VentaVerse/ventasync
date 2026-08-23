<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The listing's answers to its category's attribute sheet (#98).
 *
 * One JSON map (attribute_values, NOT `attributes`: that name is
 * Eloquent's own internal property and a column wearing it invites
 * silent collisions), attribute key -> the operator's value. The KEYS are
 * Shopee's numeric attribute ids as strings (the same keys the category
 * template names); the values are what the operator typed or picked.
 * Free-form by design: the set of attributes is the category's to
 * define and changes when Shopee changes it, so a pivot table would
 * add joins without adding shape. The category template cached in
 * shopee_category_templates is what gives these keys meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->json('attribute_values')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_listings', function (Blueprint $table) {
            $table->dropColumn('attribute_values');
        });
    }
};
