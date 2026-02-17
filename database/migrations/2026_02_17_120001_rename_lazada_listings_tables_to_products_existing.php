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
        /**
         * Rename existing "listing(s)" tables -> "product" tables.
         * This is idempotent: it only renames when FROM exists and TO does not.
         */

        // Parent table (handle both possible singular/plural naming)
        $this->renameTableIfNeeded('lazada_listings', 'lazada_products');
        $this->renameTableIfNeeded('lazada_listing',  'lazada_products');

        // Child tables (your actual names are singular: lazada_listing_*)
        $this->renameTableIfNeeded('lazada_listing_attributes', 'lazada_product_attributes');
        $this->renameTableIfNeeded('lazada_listing_variants',   'lazada_product_variants');

        // Fallbacks if some environments used plural: lazada_listings_*
        $this->renameTableIfNeeded('lazada_listings_attributes', 'lazada_product_attributes');
        $this->renameTableIfNeeded('lazada_listings_variants',   'lazada_product_variants');

        // If the new parent table doesn't exist, nothing else to do.
        $parentTable = 'lazada_products';
        if (!Schema::hasTable($parentTable)) {
            return;
        }

        // Determine parent PK column (default to "id" if not detected)
        $parentPk = $this->getPrimaryKeyColumn($parentTable) ?: 'id';

        /**
         * Rebind foreign keys on child tables' lazada_listing_id column
         * to point to lazada_products.<pk>.
         *
         * NOTE: We keep the child column name as lazada_listing_id for compatibility,
         * and only adjust constraints.
         */
        $this->rebindForeignKeyToParent(
            childTable: 'lazada_product_attributes',
            childColumn: 'lazada_listing_id',
            parentTable: $parentTable,
            parentColumn: $parentPk
        );

        $this->rebindForeignKeyToParent(
            childTable: 'lazada_product_variants',
            childColumn: 'lazada_listing_id',
            parentTable: $parentTable,
            parentColumn: $parentPk
        );
    }

    public function down(): void
    {
        /**
         * Reverse: product tables -> listing tables (best-effort).
         * Also rebind foreign keys back to lazada_listings if it exists.
         */

        $listingsParent = 'lazada_listings';
        if (Schema::hasTable($listingsParent)) {
            $parentPk = $this->getPrimaryKeyColumn($listingsParent) ?: 'id';

            $this->rebindForeignKeyToParent(
                childTable: 'lazada_product_attributes',
                childColumn: 'lazada_listing_id',
                parentTable: $listingsParent,
                parentColumn: $parentPk
            );

            $this->rebindForeignKeyToParent(
                childTable: 'lazada_product_variants',
                childColumn: 'lazada_listing_id',
                parentTable: $listingsParent,
                parentColumn: $parentPk
            );
        }

        // Rename back (idempotent)
        $this->renameTableIfNeeded('lazada_products', 'lazada_listings');

        $this->renameTableIfNeeded('lazada_product_attributes', 'lazada_listing_attributes');
        $this->renameTableIfNeeded('lazada_product_variants',   'lazada_listing_variants');

        // Fallback reverses if needed
        $this->renameTableIfNeeded('lazada_product_attributes', 'lazada_listings_attributes');
        $this->renameTableIfNeeded('lazada_product_variants',   'lazada_listings_variants');
    }

    private function renameTableIfNeeded(string $from, string $to): void
    {
        if (Schema::hasTable($from) && !Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    /**
     * Drops any existing FK constraint(s) on childTable.childColumn (if present),
     * then adds a new FK to parentTable.parentColumn.
     */
    private function rebindForeignKeyToParent(
        string $childTable,
        string $childColumn,
        string $parentTable,
        string $parentColumn
    ): void {
        if (!Schema::hasTable($childTable)) {
            return;
        }
        if (!$this->columnExists($childTable, $childColumn)) {
            return;
        }
        if (!Schema::hasTable($parentTable)) {
            return;
        }

        // Drop existing FK(s) on this column (robust even with non-standard names)
        $fkNames = $this->getForeignKeyConstraintNames($childTable, $childColumn);
        foreach ($fkNames as $fkName) {
            DB::statement("ALTER TABLE `{$childTable}` DROP FOREIGN KEY `{$fkName}`");
        }

        // Add new FK (use a deterministic name)
        $newFkName = $this->makeFkName($childTable, $childColumn, $parentTable);

        // Avoid duplicates
        if ($this->foreignKeyNameExists($childTable, $newFkName)) {
            return;
        }

        Schema::table($childTable, function (Blueprint $table) use ($childColumn, $parentTable, $parentColumn, $newFkName) {
            $table->foreign($childColumn, $newFkName)
                ->references($parentColumn)
                ->on($parentTable)
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$this->dbName, $table, $column]
        );

        return (int)($row->cnt ?? 0) > 0;
    }

    private function getPrimaryKeyColumn(string $table): ?string
    {
        $row = DB::selectOne(
            "SELECT k.COLUMN_NAME AS col
             FROM information_schema.TABLE_CONSTRAINTS t
             JOIN information_schema.KEY_COLUMN_USAGE k
               ON t.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              AND t.TABLE_SCHEMA = k.TABLE_SCHEMA
              AND t.TABLE_NAME = k.TABLE_NAME
             WHERE t.TABLE_SCHEMA = ?
               AND t.TABLE_NAME = ?
               AND t.CONSTRAINT_TYPE = 'PRIMARY KEY'
             ORDER BY k.ORDINAL_POSITION ASC
             LIMIT 1",
            [$this->dbName, $table]
        );

        return $row?->col ?? null;
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

    private function makeFkName(string $childTable, string $childColumn, string $parentTable): string
    {
        // MySQL identifier limit is 64 chars; keep some buffer
        $base = "fk_{$childTable}_{$childColumn}_to_{$parentTable}";
        return substr($base, 0, 60);
    }
};
