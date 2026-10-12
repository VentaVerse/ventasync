<?php

namespace Tests\Feature;

use App\Services\Catalog\VariationSnapshot;
use App\Services\Catalog\VariationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariationSnapshotRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku): int
    {
        $id = (int) DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 0, 'price' => 180, 'status' => 1, 'image' => '',
            'cost_amount' => 0, 'cost_percentage' => 5, 'cost_additional' => 3, 'cost' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'), 'name' => $sku,
            'description' => '', 'tag' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);

        return $id;
    }

    private function state(int $productId): array
    {
        $povs = DB::table('product_variation_values')->where('product_id', $productId)->orderBy('product_variation_value_id')
            ->get(['product_variation_value_id', 'variation_value_id', 'sku', 'quantity', 'absolute_price'])
            ->map(fn ($r) => (array) $r)->all();
        $combos = DB::table('product_variations')->where('product_id', $productId)->orderBy('sku')
            ->get(['sku', 'status', 'image', 'quantity', 'absolute_price', 'cost_amount', 'cost_additional', 'absolute_cost'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? round((float) $v, 4) : $v, (array) $r))->all();

        return compact('povs', 'combos');
    }

    public function test_one_type_variations_survive_a_save_unchanged(): void
    {
        $id = $this->product('flat-patch-trs');
        app(VariationWriter::class)->save($id, [
            'variation_type_name' => 'Length',
            'values' => [
                ['name' => '15cm', 'sku' => 'flat-patch-trs-15', 'quantity' => 7, 'absolute_price' => 120, 'cost_amount' => 36.37, 'cost_additional' => 2, 'image' => 'catalog/trs-15.jpg', 'status' => 1],
                ['name' => '30cm', 'sku' => 'flat-patch-trs-30', 'quantity' => -2, 'absolute_price' => 180, 'cost_amount' => 39.71, 'cost_additional' => 0, 'image' => '', 'status' => 0],
            ],
        ]);
        $before = $this->state($id);
        $historyBefore = DB::table('stock_history')->count();

        $wire = app(VariationSnapshot::class)->wire($id);
        app(VariationWriter::class)->save($id, $wire);

        $this->assertSame($before, $this->state($id));
        $this->assertSame($historyBefore, DB::table('stock_history')->count(), 'no stock movement');
    }

    public function test_two_type_variations_survive_a_save_unchanged(): void
    {
        $id = $this->product('strap');
        app(VariationWriter::class)->save($id, [
            'type1_name' => 'Color', 'type2_name' => 'Size',
            'type1_values' => ['Black', 'Brown'], 'type2_values' => ['S', 'L'],
            'combinations' => [
                ['opt1' => 'Black', 'opt2' => 'S', 'sku' => 'strap-bk-s', 'quantity' => 4, 'absolute_price' => 500, 'cost_amount' => 200, 'cost_additional' => 10, 'image' => 'catalog/bk.jpg', 'status' => 1],
                ['opt1' => 'Black', 'opt2' => 'L', 'sku' => 'strap-bk-l', 'quantity' => 0, 'absolute_price' => 520, 'cost_amount' => 210, 'cost_additional' => 0, 'image' => '', 'status' => 0],
                ['opt1' => 'Brown', 'opt2' => 'L', 'sku' => 'strap-br-l', 'quantity' => 9, 'absolute_price' => 530, 'cost_amount' => 215, 'cost_additional' => 5, 'image' => '', 'status' => 1],
            ],
        ]);
        $before = $this->state($id);

        $wire = app(VariationSnapshot::class)->wire($id);
        $this->assertSame('Color', $wire['type1_name']);
        $this->assertCount(3, $wire['combinations'], 'a missing pair stays missing');

        app(VariationWriter::class)->save($id, $wire);

        $this->assertSame($before, $this->state($id));
    }

    public function test_a_product_without_variations_has_no_snapshot(): void
    {
        $this->assertNull(app(VariationSnapshot::class)->wire($this->product('plain')));
    }
}
