<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'image_thumb_size')) {
                $table->unsignedSmallInteger('image_thumb_size')->default(200);
                $table->unsignedSmallInteger('image_preview_size')->default(500);
                $table->unsignedSmallInteger('image_push_size')->default(1000);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'image_thumb_size')) {
                $table->dropColumn(['image_thumb_size', 'image_preview_size', 'image_push_size']);
            }
        });
    }
};
