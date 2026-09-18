<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW TALL THE COMPANY LOGO STANDS IN EACH PLACE.
 *
 * Two places show it and they have different room, so each keeps its own
 * height: the sidebar row, and the sign-in panel.
 *
 * A height is a ceiling, not a size. The logo is fitted under it with its
 * shape kept, and is never stretched to reach it. The width it may use is
 * not stored: that is the room the column has, which the layout knows and a
 * seller cannot change.
 */
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
