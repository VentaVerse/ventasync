<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $dbName;

    public function __construct()
    {
        $this->dbName = DB::getDatabaseName();
    }

    public function up(): void
    {
        // Only applies to existing installs that still have lazada_listing_id columns
        $this->renameFkColumn(
            table: 'lazada_product_attributes',
            from: 'lazada_listing_id',
            to: 'lazada_product_id',
            parentTable: 'lazada_products',
            parentColumn: 'id',
            newFkName: 'fk_lazada_product_attributes_lazada_product_id'
        );

        $this->renameFkColumn(
            table: 'lazada_product_variants',
            from: 'lazada_listing_id',
            to: 'lazada_product_id',
            parentTable: 'lazada_products',
            parentColumn: 'id',
            newFkName: 'fk_lazada_product_variants_lazada_product_id'
        );
    }

    public function down(): void
    {
        // Best-effort rollback (rename back)
        $this->renameFkColumn(
            table: 'lazada_product_attributes',
            from: 'lazada_product_id',
            to: 'lazada_listing_id',
            parentTable: 'lazada_products',
            parentColumn: 'id',
            newFkName: 'fk_lazada_product_attributes_lazada_listing_id'
        );

        $this->renameFkColumn(
            table: 'lazada_product_variants',
            from: 'lazada_product_id',
            to: 'lazada_listing_id',
            parentTable: 'lazada_products',
            parentColumn: 'id',
            newFkName: 'fk_lazada_product_variants_lazada_listing_id'
        );
    }

    private function renameFkColumn(
        string $table,
        string $from,
        string $to,
        string $parentTable,
        string $parentColumn,
        string $newFkName
    ): void {
        if (!Schema::hasTable($table)) {
            return;
        }
        if (!Schema::hasColumn($table, $from)) {
            return;
        }
        if (Schema::hasColumn($table, $to)) {
            // Column already renamed
            return;
        }

        // Drop any FK constraints that reference the old column (constraint names may vary)
        foreach ($this->getForeignKeyConstraintNames($table, $from) as $fkName) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
        }

        // Rename column using raw SQL (avoids requiring doctrine/dbal)
        // Assumes BIGINT UNSIGNED NOT NULL (matches unsignedBigInteger in Laravel)
        DB::statement("ALTER TABLE `{$table}` CHANGE `{$from}` `{$to}` BIGINT UNSIGNED NOT NULL");

        // Add FK constraint back to parent
        if (Schema::hasTable($parentTable)) {
            // Ensure we don't collide with an existing FK name
            $fkName = substr($newFkName, 0, 60);
            if (!$this->foreignKeyNameExists($table, $fkName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($to, $parentTable, $parentColumn, $fkName) {
                    $blueprint->foreign($to, $fkName)
                        ->references($parentColumn)
                        ->on($parentTable)
                        ->onUpdate('cascade')
                        ->onDelete('cascade');
                });
            }
        }
    }

    private function getForeignKeyConstraintNames(string $table, string $column): array
    {
        $rows = DB::select(
            "SELECT CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$this->dbName, $table, $column]
        );

        return array_values(array_filter(array_map(fn($r) => $r->name ?? null, $rows)));
    }

    private function foreignKeyNameExists(string $table, string $fkName): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$this->dbName, $table, $fkName]
        );

        return (int)($row->cnt ?? 0) > 0;
    }
};
