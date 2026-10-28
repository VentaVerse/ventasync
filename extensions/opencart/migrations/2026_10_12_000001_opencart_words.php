<?php

use App\Support\Database\SchemaRenames;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    use SchemaRenames;

    private const COLUMNS = [
        ['ch_opencart_listing_links', 'oc_product_id', 'opencart_product_id'],
        ['ch_opencart_order_status_map', 'oc_status_id', 'opencart_status_id'],
        ['ch_opencart_order_status_map', 'oc_status_name', 'opencart_status_name'],
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
