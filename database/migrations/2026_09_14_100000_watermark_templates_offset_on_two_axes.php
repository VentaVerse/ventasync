<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE MARGIN BECOMES AN OFFSET, ON TWO AXES (VENT2-128).
 *
 * the owner, 2026-09-11: "Rename margin to offset. Then I want to make offset
 * both X and Y axis. Separated slider." And of the sliders: "default is 0
 * and can go to negative and positive."
 *
 * A margin could only push a mark inward from its corner, by the same
 * amount on both sides. An offset moves it anywhere, independently on each
 * axis, in the picture's own directions: right and down are positive, the
 * way every image editor counts them.
 *
 * Existing templates keep exactly where their mark sits. A margin of m on
 * a bottom-right mark was m in from the right and m up from the bottom,
 * which is an offset of -m, -m; a top-left mark was +m, +m; a centred axis
 * had no margin on it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watermark_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('watermark_templates', 'offset_x_percent')) {
                $table->decimal('offset_x_percent', 5, 2)->default(0)->after('size_percent');
            }
            if (! Schema::hasColumn('watermark_templates', 'offset_y_percent')) {
                $table->decimal('offset_y_percent', 5, 2)->default(0)->after('offset_x_percent');
            }
        });

        if (Schema::hasColumn('watermark_templates', 'margin_percent')) {
            foreach (DB::table('watermark_templates')->get(['id', 'position', 'margin_percent']) as $row) {
                [$vertical, $horizontal] = array_pad(explode('-', (string) $row->position), 2, 'right');
                $m = (float) $row->margin_percent;
                DB::table('watermark_templates')->where('id', $row->id)->update([
                    'offset_x_percent' => match ($horizontal) { 'left' => $m, 'center' => 0, default => -$m },
                    'offset_y_percent' => match ($vertical) { 'top' => $m, 'middle' => 0, default => -$m },
                ]);
            }

            Schema::table('watermark_templates', function (Blueprint $table) {
                $table->dropColumn('margin_percent');
            });
        }
    }

    public function down(): void
    {
        Schema::table('watermark_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('watermark_templates', 'margin_percent')) {
                $table->decimal('margin_percent', 5, 2)->default(3)->after('size_percent');
            }
        });

        foreach (DB::table('watermark_templates')->get(['id', 'offset_x_percent', 'offset_y_percent']) as $row) {
            DB::table('watermark_templates')->where('id', $row->id)->update([
                'margin_percent' => min(40, max(abs((float) $row->offset_x_percent), abs((float) $row->offset_y_percent))),
            ]);
        }

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->dropColumn(['offset_x_percent', 'offset_y_percent']);
        });
    }
};
