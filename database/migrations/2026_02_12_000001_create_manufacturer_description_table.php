<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');
        $table = $p.'manufacturer_description';

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->integer('manufacturer_id');
            $t->integer('language_id');
            $t->longText('description')->nullable();

            $t->primary(['manufacturer_id','language_id']);
        });
    }

    public function down(): void
    {
        // Intentionally not dropping catalog tables.
    }
};
