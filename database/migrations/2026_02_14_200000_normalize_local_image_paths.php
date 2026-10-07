<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $p = (string) config('catalog.prefix', '');

        $this->normalizeColumn($p . 'product', 'image');
        $this->normalizeColumn($p . 'option_value', 'image');
    }

    private function normalizeColumn(string $table, string $column): void
    {
        try {
            DB::table($table)->limit(1)->get([$column]);
        } catch (\Throwable $e) {
            return;
        }

        DB::statement("
            UPDATE `{$table}`
            SET `{$column}` = CASE
                WHEN `{$column}` IS NULL THEN `{$column}`
                WHEN `{$column}` = '' THEN `{$column}`

                WHEN `{$column}` LIKE 'http%' AND LOCATE('/image/', `{$column}`) > 0
                    THEN SUBSTRING(`{$column}`, LOCATE('/image/', `{$column}`) + 7)

                WHEN `{$column}` LIKE '/image/%'
                    THEN SUBSTRING(`{$column}`, 8)

                WHEN `{$column}` LIKE 'image/%'
                    THEN SUBSTRING(`{$column}`, 7)

                WHEN `{$column}` LIKE '/storage/%'
                    THEN SUBSTRING(`{$column}`, 10)

                WHEN `{$column}` LIKE 'storage/%'
                    THEN SUBSTRING(`{$column}`, 9)

                ELSE `{$column}`
            END
            WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''
        ");
    }

    public function down(): void
    {
    }
};
