<?php

use App\Support\Database\SchemaRenames;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use SchemaRenames;

    private const COLUMNS = [
        ['ch_shopee_orders', 'catalog_order_id', 'sales_order_id'],
        ['ch_shopee_orders', 'buyer_invoice', 'customer_invoice'],
    ];

    private const INDEXES = [
        ['ch_shopee_orders', 'ch_shopee_orders_catalog_order_id_index', 'ch_shopee_orders_sales_order_id_index'],
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
