<?php

use App\Support\Database\SchemaRenames;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use SchemaRenames;

    private const COLUMNS = [
        ['ch_ventacart_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_ventacart_brands', 'is_active', 'enabled'],
        ['ch_ventacart_categories', 'is_active', 'enabled'],
    ];

    private const INDEXES = [
        ['ch_ventacart_orders', 'ch_ventacart_orders_catalog_order_id_index', 'ch_ventacart_orders_sales_order_id_index'],
    ];

    public function up(): void
    {
        $this->renameColumns(self::COLUMNS);
        $this->renameIndexes(self::INDEXES);
    }

    public function down(): void
    {
        $this->restoreIndexes(self::INDEXES);
        $this->restoreColumns(self::COLUMNS);
    }
};
