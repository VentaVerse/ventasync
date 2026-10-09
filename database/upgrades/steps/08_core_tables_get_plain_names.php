<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIXED = [
        'brand' => 'brands',
        'category' => 'categories',
        'category_description' => 'category_descriptions',
        'category_path' => 'category_paths',
        'option' => 'variation_types',
        'option_description' => 'variation_type_descriptions',
        'option_value' => 'variation_values',
        'option_value_description' => 'variation_value_descriptions',
        'order' => 'orders',
        'order_history' => 'order_history',
        'order_option' => 'order_options',
        'order_product' => 'order_products',
        'order_status' => 'order_statuses',
        'order_total' => 'order_totals',
        'product' => 'products',
        'product_description' => 'product_descriptions',
        'product_image' => 'product_images',
        'product_option' => 'product_variation_types',
        'product_option_value' => 'product_variation_values',
        'product_special' => 'product_specials',
        'product_to_category' => 'product_categories',
    ];

    private const UNPREFIXED = [
        'product_option_combinations' => 'product_variations',
        'product_option_combination_values' => 'product_variation_parts',
    ];

    private function renames(): array
    {
        $prefix = (string) env('DB_TABLE_PREFIX', '');
        $out = [];
        foreach (self::PREFIXED as $old => $new) {
            $out[] = [$prefix . $old, $new];
        }
        foreach (self::UNPREFIXED as $old => $new) {
            $out[] = [$old, $new];
        }

        return $out;
    }

    public function up(): void
    {
        foreach ($this->renames() as [$from, $to]) {
            if ($from !== $to && Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->renames()) as [$from, $to]) {
            if ($from !== $to && Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
