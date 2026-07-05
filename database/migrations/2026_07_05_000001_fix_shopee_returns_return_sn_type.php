<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shopee return_sn values are alphanumeric (e.g. "2606300ENWS2606"), not numeric.
     * The column was created as bigint, which truncates the value and fails the insert
     * under strict SQL mode. Change it to varchar to match order_sn.
     */
    public function up(): void
    {
        if (!Schema::hasTable('shopee_returns')) {
            return;
        }

        Schema::table('shopee_returns', function (Blueprint $table) {
            $table->string('return_sn', 64)->change();
        });
    }

    public function down(): void
    {
        // Not reverting: bigint cannot safely hold alphanumeric return_sn values.
    }
};
