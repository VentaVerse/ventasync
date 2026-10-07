<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariationCostBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;
    private array $pov = [];
    private array $combo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id', 1);

        $this->productId = DB::table($p . 'product')->insertGetId([
            'model' => 'GP200', 'sku' => 'valeton-gp-200', 'quantity' => 0, 'price' => 21990, 'status' => 1, 'image' => '',
            'cost_amount' => 10000, 'cost_percentage' => 0, 'cost_additional' => 220, 'cost' => 10220,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p . 'product_description')->insert([
            'product_id' => $this->productId, 'language_id' => $langId, 'name' => 'Valeton GP-200',
            'description' => '', 'tag' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);
        $optionId = DB::table($p . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        $productOptionId = DB::table($p . 'product_option')->insertGetId([
            'product_id' => $this->productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        $rows = [
            'red'   => ['absolute_price' => 21990, 'cost_amount' => 0, 'cost_additional' => 220, 'absolute_cost' => 220, 'cost' => 0],
            'black' => ['absolute_price' => 21990, 'cost_amount' => 10000, 'cost_additional' => 220, 'absolute_cost' => 0, 'cost' => 10220],
            'gold'  => ['absolute_price' => 25990, 'cost_amount' => 12000, 'cost_additional' => 220, 'absolute_cost' => 12220, 'cost' => 0],
            'old'   => ['absolute_price' => 21990, 'cost_amount' => 0, 'cost_additional' => 0, 'absolute_cost' => 9800, 'cost' => 0],
        ];
        foreach ($rows as $key => $cols) {
            $ovId = DB::table($p . 'option_value')->insertGetId(['option_id' => $optionId, 'image' => '', 'sort_order' => 0]);
            $this->pov[$key] = DB::table($p . 'product_option_value')->insertGetId(array_merge([
                'product_option_id' => $productOptionId, 'product_id' => $this->productId,
                'option_id' => $optionId, 'option_value_id' => $ovId, 'sku' => 'gp-' . $key, 'quantity' => 0,
                'cost_percentage' => 0, 'cost_prefix' => '+',
            ], $cols));
            $this->combo[$key] = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $this->productId, 'sku' => 'gp-' . $key, 'status' => 1, 'quantity' => 0,
                'absolute_price' => $cols['absolute_price'], 'cost_amount' => $cols['cost_amount'],
                'cost_additional' => $cols['cost_additional'], 'absolute_cost' => $cols['absolute_cost'],
                'subtract' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                'combination_id' => $this->combo[$key], 'product_option_value_id' => $this->pov[$key],
            ]);
        }
    }

    public function test_it_reports_without_writing_by_default(): void
    {
        $this->artisan('catalog:backfill-variation-costs')
            ->expectsOutputToContain('Valeton GP-200')
            ->expectsOutputToContain('would change')
            ->assertSuccessful();

        $this->assertEquals(220, DB::table(config('catalog.prefix') . 'product_option_value')->where('product_option_value_id', $this->pov['red'])->value('absolute_cost'));
    }

    public function test_it_gives_the_parent_amount_and_additional_and_composes_the_unit_cost(): void
    {
        $this->artisan('catalog:backfill-variation-costs', ['--apply' => true])->assertSuccessful();
        $p = config('catalog.prefix');

        $red = DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['red'])->first();
        $this->assertEquals(10000, $red->cost_amount);
        $this->assertEquals(220, $red->cost_additional);
        $this->assertEquals(10220, $red->absolute_cost, 'overhead-only becomes the parent\'s 10,000 + 220');

        $black = DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['black'])->first();
        $this->assertEquals(10220, $black->absolute_cost, 'an amount without an absolute is composed');
        $this->assertEquals(0, $black->cost, 'the delta column is cleared of the PO total');

        $gold = DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['gold'])->first();
        $this->assertEquals(12000, $gold->cost_amount, 'a variation with its own amount keeps it');
        $this->assertEquals(12220, $gold->absolute_cost);

        $old = DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['old'])->first();
        $this->assertEquals(9800, $old->absolute_cost, 'a cost typed before the formula is the variation\'s own and is kept');

        $combos = DB::table('product_option_combinations')->where('product_id', $this->productId)->get()->keyBy('sku');
        $this->assertEquals(10220, $combos['gp-red']->absolute_cost);
        $this->assertEquals(10000, $combos['gp-red']->cost_amount);
        $this->assertEquals(12220, $combos['gp-gold']->absolute_cost);
    }

    public function test_overwrite_gives_every_variation_the_parents(): void
    {
        $this->artisan('catalog:backfill-variation-costs', ['--apply' => true, '--overwrite' => true])->assertSuccessful();
        $p = config('catalog.prefix');

        $gold = DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['gold'])->first();
        $this->assertEquals(10000, $gold->cost_amount);
        $this->assertEquals(10220, $gold->absolute_cost, 'the percentage is 0, so the dearer price changes nothing');
        $this->assertEquals(10220, DB::table($p . 'product_option_value')->where('product_option_value_id', $this->pov['old'])->value('absolute_cost'));
    }

    public function test_the_order_backfill_then_reads_the_repaired_figure(): void
    {
        $this->artisan('catalog:backfill-variation-costs', ['--apply' => true])->assertSuccessful();
        $this->assertSame(10220.0, \App\Support\LineCost::resolve($this->productId, $this->pov['red']));
        $this->assertSame(10220.0, \App\Support\LineCost::resolve($this->productId, $this->pov['black']));
        $this->assertSame(12220.0, \App\Support\LineCost::resolve($this->productId, $this->pov['gold']));
    }
}
