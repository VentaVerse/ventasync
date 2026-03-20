<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('company_name');
            $table->string('address_line1', 255)->nullable()->after('phone');
            $table->string('address_line2', 255)->nullable()->after('address_line1');
            $table->string('city', 128)->nullable()->after('address_line2');
            $table->string('state', 128)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->string('country', 128)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']);
        });
    }
};
