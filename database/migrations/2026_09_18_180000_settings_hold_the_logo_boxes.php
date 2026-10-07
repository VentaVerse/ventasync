<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BOXES = [
        'logo_nav_h' => 44,
        'logo_login_h' => 96,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            foreach (self::BOXES as $column => $default) {
                if (! Schema::hasColumn('settings', $column)) {
                    $table->unsignedSmallInteger($column)->default($default);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            foreach (array_keys(self::BOXES) as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
