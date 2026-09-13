<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a product's pictures get the mark is chosen on the listing, so
 * the template no longer carries a main-image switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('watermark_templates', 'stamp_main')) {
            Schema::table('watermark_templates', function (Blueprint $table) {
                $table->dropColumn('stamp_main');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('watermark_templates', 'stamp_main')) {
            Schema::table('watermark_templates', function (Blueprint $table) {
                $table->boolean('stamp_main')->default(false);
            });
        }
    }
};
