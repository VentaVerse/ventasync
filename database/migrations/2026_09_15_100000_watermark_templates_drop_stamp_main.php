<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
