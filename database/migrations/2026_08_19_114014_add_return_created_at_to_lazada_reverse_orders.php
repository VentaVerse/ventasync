<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_reverse_orders', function (Blueprint $table) {
            $table->timestamp('return_created_at')->nullable()->after('currency');
            $table->index(['region', 'return_created_at']);
        });

        DB::table('lazada_reverse_orders')
            ->select('id', 'raw')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    if (!is_array($raw)) {
                        continue;
                    }

                    $stamps = [];
                    foreach ((array) ($raw['reverse_order_lines'] ?? []) as $line) {
                        $ts = $line['return_order_line_gmt_create'] ?? null;
                        if (is_numeric($ts) && (int) $ts > 0) {
                            $stamps[] = (int) $ts;
                        }
                    }

                    if ($stamps === []) {
                        continue;
                    }

                    DB::table('lazada_reverse_orders')
                        ->where('id', $row->id)
                        ->update(['return_created_at' => date('Y-m-d H:i:s', min($stamps))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('lazada_reverse_orders', function (Blueprint $table) {
            $table->dropIndex(['region', 'return_created_at']);
            $table->dropColumn('return_created_at');
        });
    }
};
