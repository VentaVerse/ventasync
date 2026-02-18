<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('catalog.prefix');
        Schema::dropIfExists($p . 'manufacturer_description');
    }

    public function down(): void
    {
        // Not restoring — table was removed intentionally.
    }
};
