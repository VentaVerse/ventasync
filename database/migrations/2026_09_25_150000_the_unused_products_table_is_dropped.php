<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE UNUSED `products` TABLE IS DROPPED.
 *
 * A starter-kit table from the first day (2026_02_11_161329), never read or
 * written: the catalogue has always lived in the OpenCart-shaped `product`.
 * Its create migration is deleted, so a fresh install never has it; this
 * removes it from the installs that already do, and does nothing where it
 * never existed.
 *
 * No down(): the table held nothing, and putting it back would only bring
 * the dead weight back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        // Nothing to restore.
    }
};
