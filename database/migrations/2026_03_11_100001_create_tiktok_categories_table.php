<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_categories', function (Blueprint $table) {
            $table->string('id')->primary(); // TikTok's category_id, not auto-increment
            $table->string('parent_id')->nullable();
            $table->string('name');
            $table->boolean('is_leaf')->default(false);
            $table->json('permission_statuses')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_categories');
    }
};
