<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $dbName;

    public function __construct()
    {
        $this->dbName = DB::getDatabaseName();
    }

    public function up(): void
    {
        // Standardize FK columns to lazada_product_id (remove legacy lazada_listing_id)
        $this->standardizeAttributesTable();
        $this->standardizeVariantsTable();
    }

    public function down(): void
    {
        // Intentionally no down migration.
        // This project standardizes on lazada_product_id going forward.
    }

    private function standardizeAttributesTable(): void
    {
        $table = 'lazada_product_attributes';
        if (!Schema::hasTable($table)) {
            return;
        }

        // If already standardized, nothing to do.
        if (Schema::hasColumn($table, 'lazada_product_id')) {
            return;
        }
        if (!Schema::hasColumn($table, 'lazada_listing_id')) {
            return;
        }

        // Drop FK(s) on lazada_listing_id (constraint names can vary)
        $this->dropForeignKeysOnColumn($table, 'lazada_listing_id');

        // Drop legacy unique index if present
        $this->dropIndexIfExists($table, 'lazada_listing_attr_unique');

        // Rename column with explicit type to avoid doctrine/dbal dependency
        DB::statement("ALTER TABLE `{$table}` CHANGE `lazada_listing_id` `lazada_product_id` BIGINT UNSIGNED NOT NULL");

        // Create new unique index (if missing)
        $this->createUniqueIfMissing($table, 'lazada_product_attr_unique', ['lazada_product_id', 'attribute_key']);

        // Create FK (if missing)
        $this->createForeignKeyIfMissing(
            table: $table,
            fkName: 'fk_lazada_product_attributes_product',
            column: 'lazada_product_id',
            refTable: 'lazada_products',
            refColumn: 'id',
            onDelete: 'CASCADE',
            onUpdate: 'CASCADE'
        );
    }

    private function standardizeVariantsTable(): void
    {
        $table = 'lazada_product_variants';
        if (!Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'lazada_product_id')) {
            return;
        }
        if (!Schema::hasColumn($table, 'lazada_listing_id')) {
            return;
        }

        $this->dropForeignKeysOnColumn($table, 'lazada_listing_id');

        $this->dropIndexIfExists($table, 'lazada_listing_variant_unique');

        DB::statement("ALTER TABLE `{$table}` CHANGE `lazada_listing_id` `lazada_product_id` BIGINT UNSIGNED NOT NULL");

        $this->createUniqueIfMissing($table, 'lazada_product_variant_unique', ['lazada_product_id', 'product_option_value_id']);

        $this->createForeignKeyIfMissing(
            table: $table,
            fkName: 'fk_lazada_product_variants_product',
            column: 'lazada_product_id',
            refTable: 'lazada_products',
            refColumn: 'id',
            onDelete: 'CASCADE',
            onUpdate: 'CASCADE'
        );
    }

    private function dropForeignKeysOnColumn(string $table, string $column): void
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

        foreach ($rows as $r) {
            $name = $r->name ?? null;
            if (!$name) continue;
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$this->dbName, $table, $indexName]
        );
        if ((int)($row->cnt ?? 0) > 0) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }

    private function createUniqueIfMissing(string $table, string $indexName, array $columns): void
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$this->dbName, $table, $indexName]
        );
        if ((int)($row->cnt ?? 0) > 0) {
            return;
        }

        $cols = implode('`,`', $columns);
        DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$indexName}` (`{$cols}`)");
    }

    private function createForeignKeyIfMissing(
        string $table,
        string $fkName,
        string $column,
        string $refTable,
        string $refColumn,
        string $onDelete,
        string $onUpdate
    ): void {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$this->dbName, $table, $fkName]
        );

        if ((int)($row->cnt ?? 0) > 0) {
            return;
        }

        DB::statement(
            "ALTER TABLE `{$table}`
             ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`)
             REFERENCES `{$refTable}`(`{$refColumn}`)
             ON UPDATE {$onUpdate} ON DELETE {$onDelete}"
        );
    }
};
