<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Shopee return_sn is alphanumeric, so the column must be a string, not a bigint.
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
    }
};
