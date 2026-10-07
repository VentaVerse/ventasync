<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
