<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When Lazada opened the return, as opposed to when this ERP first saw it.
 *
 * The returns list had no date filter because there was no honest column to
 * filter on: the table carried only created_at and updated_at, which are row
 * timestamps and move when a sync touches the row. Shopee's equivalent table
 * has had return_created_at from the start, so the two returns screens could
 * not offer the same From and To.
 *
 * The value was already arriving and being dropped - Lazada sends
 * return_order_line_gmt_create on each reverse order line, a Unix timestamp,
 * inside the payload this table stores whole in raw. So this lifts it into a
 * column and backfills every existing row from the raw already on disk,
 * rather than asking the operator to re-sync a history to get a filter.
 *
 * Nullable, because a row whose raw predates that field or arrived malformed
 * gets no date rather than a wrong one. The list treats null as "unknown"
 * and a From/To filter excludes it, which is the correct answer to "opened in
 * this range" when the opening date is not known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_reverse_orders', function (Blueprint $table) {
            $table->timestamp('return_created_at')->nullable()->after('currency');
            $table->index(['region', 'return_created_at']);
        });

        // Backfill in chunks: raw is a JSON blob per row, so this reads and
        // writes rather than doing it in SQL, and chunking keeps a large
        // history from being held in memory at once.
        DB::table('lazada_reverse_orders')
            ->select('id', 'raw')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    if (!is_array($raw)) {
                        continue;
                    }

                    // Take the earliest line: a reverse order can carry
                    // several lines and the return was opened when the first
                    // of them was.
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
