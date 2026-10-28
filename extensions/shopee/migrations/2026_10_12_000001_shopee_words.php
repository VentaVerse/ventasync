<?php

use App\Support\Database\SchemaRenames;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use SchemaRenames;

    private const COLUMNS = [
        ['ch_shopee_order_products', 'item_id', 'shopee_item_id'],
        ['ch_shopee_order_products', 'model_id', 'shopee_model_id'],
    ];

    public function up(): void
    {
        $this->renameColumns(self::COLUMNS);
    }

    public function down(): void
    {
        $this->restoreColumns(self::COLUMNS);
    }
};
