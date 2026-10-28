<?php

use App\Support\Database\SchemaRenames;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use SchemaRenames;

    private const COLUMNS = [
        ['ch_ventacart_order_products', 'variant_label', 'variation_label'],
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
