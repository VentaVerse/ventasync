<?php

namespace Tests\Feature;

use App\Support\VariationRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariationRowsTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduct(string $model): int
    {
        $pfx = (string) config('catalog.prefix');

        return (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $model, 'sku' => $model, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
    }

    private function seedOptionScaffold(int $productId, string $valueName): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Colour',
        ]);
        $valueId = (int) DB::table($pfx . 'option_value')->insertGetId([
            'option_id' => $optionId, 'image' => '', 'sort_order' => 0,
        ]);
        DB::table($pfx . 'option_value_description')->insert([
            'option_value_id' => $valueId, 'language_id' => $langId,
            'option_id' => $optionId, 'name' => $valueName,
        ]);
        $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        return (int) DB::table($pfx . 'product_option_value')->insertGetId([
            'product_option_id' => $productOptionId, 'product_id' => $productId,
            'option_id' => $optionId, 'option_value_id' => $valueId,
            'sku' => 'POV-' . $valueName, 'quantity' => 3, 'subtract' => 1,
            'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
            'weight' => 0, 'weight_prefix' => '+',
            'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
            'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 90,
        ]);
    }

    public function test_rows_stay_keyed_by_product_id_across_both_sources(): void
    {
        $comboProduct = $this->seedProduct('VR-COMBO');
        $povId = $this->seedOptionScaffold($comboProduct, 'Red');
        $comboId = (int) DB::table('product_option_combinations')->insertGetId([
            'product_id' => $comboProduct, 'sku' => 'COMBO-RED', 'quantity' => 14,
            'absolute_price' => 21990, 'image' => 'catalog/red.png', 'status' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_option_combination_values')->insert([
            'combination_id' => $comboId, 'product_option_value_id' => $povId,
        ]);

        $povProduct = $this->seedProduct('VR-POV');
        $this->seedOptionScaffold($povProduct, 'Blue');

        $rows = VariationRows::forProducts([$comboProduct, $povProduct]);

        $this->assertEqualsCanonicalizing([$comboProduct, $povProduct], $rows->keys()->all());

        $combo = $rows->get($comboProduct)->first();
        $this->assertSame('COMBO-RED', $combo->sku);
        $this->assertSame('Red', $combo->option_value_name);
        $this->assertSame('Colour', $combo->option_name);
        $this->assertSame('catalog/red.png', $combo->option_image);
        $this->assertSame(14, (int) $combo->quantity);

        $pov = $rows->get($povProduct)->first();
        $this->assertSame('POV-Blue', $pov->sku);
        $this->assertSame('Blue', $pov->option_value_name);
        $this->assertNull($pov->option_image, 'pov rows predate the image column.');
    }

    public function test_a_product_with_no_variations_contributes_no_key(): void
    {
        $plain = $this->seedProduct('VR-PLAIN');

        $this->assertTrue(VariationRows::forProducts([$plain])->isEmpty());
        $this->assertTrue(VariationRows::forProducts([])->isEmpty());
    }
}
