<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A variation costs what the product's cost equation says it costs.
 *
 * The product composes its unit cost from three parts - a fixed amount, a
 * share of the selling price, and an additional - and prints the total. A
 * variation had one flat number and no equation at all, so a product carrying
 * 220 of inbound freight applied it to the parent and to none of the units
 * actually bought and sold.
 *
 * ONLY THE AMOUNT VARIES PER ROW. A commission share and inbound freight
 * describe how the product is bought and sold, not which size is on the
 * label. They stay on the parent and reach every variation - computed against
 * that variation's OWN price, which is the part that makes a percentage worth
 * applying per row rather than once.
 */
class VariationCostFormulaTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $group = UserGroup::create(['name' => 'Costing']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_products', 'view_products'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** A product with one axis and the parent formula already set. */
    private function product(float $pct, float $add, array $valueNames = ['Small', 'Large']): int
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-COST', 'sku' => 'SKU-COST', 'quantity' => 0,
            'price' => 1000.0000, 'status' => 1, 'image' => 'catalog/parent.jpg',
            'cost_amount' => 0, 'cost_percentage' => $pct, 'cost_additional' => $add,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId, 'name' => 'Cost Tee',
            'description' => '', 'tag' => '', 'meta_title' => 'Cost Tee',
            'meta_description' => '', 'meta_keyword' => '',
        ]);

        $optionId = DB::table($p.'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($p.'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Size',
        ]);
        $productOptionId = DB::table($p.'product_option')->insertGetId([
            'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        foreach ($valueNames as $name) {
            $ovId = DB::table($p.'option_value')->insertGetId([
                'option_id' => $optionId, 'image' => '', 'sort_order' => 0,
            ]);
            DB::table($p.'option_value_description')->insert([
                'option_value_id' => $ovId, 'language_id' => $langId,
                'option_id' => $optionId, 'name' => $name,
            ]);
            DB::table($p.'product_option_value')->insert([
                'product_option_id' => $productOptionId, 'product_id' => $productId,
                'option_id' => $optionId, 'option_value_id' => $ovId,
                'sku' => '', 'quantity' => 0,
            ]);
        }

        return $productId;
    }

    private function payload(int $productId, array $extra): array
    {
        return array_merge([
            'name' => 'Cost Tee',
            'model' => 'MDL-COST',
            'sku' => 'SKU-COST',
            'price' => 1000,
            'quantity' => 0,
            'status' => 1,
            // The parent's own pricing fields ride in the same submission,
            // because the form posts them together and the row in the
            // database is still the old one while the variations are written.
            'cost_amount' => 0,
            'cost_percentage' => 2,
            'cost_additional' => 220,
            '_options_format' => 'absolute',
        ], $extra);
    }

    /**
     * The single-axis path. It rebuilds combinations from
     * product_option_value rows, so the amount has to be carried across the
     * same way the image and the status are - three features have now been
     * broken by forgetting exactly that.
     */
    public function test_a_single_axis_variation_gets_the_parent_percentage_and_additional(): void
    {
        $productId = $this->product(2, 220);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->payload($productId, [
                'option_name' => 'Size',
                'values' => [
                    ['name' => 'Small', 'sku' => 'S', 'image' => '', 'quantity' => 3,
                     'absolute_price' => 1000, 'cost_amount' => 500],
                    ['name' => 'Large', 'sku' => 'L', 'image' => '', 'quantity' => 5,
                     'absolute_price' => 2000, 'cost_amount' => 500],
                ],
            ]))->assertRedirect();

        $rows = DB::table('product_option_combinations')
            ->where('product_id', $productId)->get()->keyBy('sku');

        // The amount is stored as typed.
        $this->assertEqualsWithDelta(500, (float) $rows['S']->cost_amount, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $rows['L']->cost_amount, 0.0001);

        // 500 + 2% of 1000 + 220 = 740
        $this->assertEqualsWithDelta(740, (float) $rows['S']->absolute_cost, 0.0001,
            'the parent additional and percentage must reach the variation');

        // 500 + 2% of 2000 + 220 = 760. THE POINT OF COMPUTING PER ROW:
        // the same amount on a dearer variation is a different unit cost.
        $this->assertEqualsWithDelta(760, (float) $rows['L']->absolute_cost, 0.0001,
            'the percentage must be charged against the variation own price');
    }

    /**
     * The two-axis path is a different method with its own write site, so it
     * gets its own assertion rather than being assumed to follow.
     */
    public function test_a_two_axis_combination_gets_the_same_equation(): void
    {
        $productId = $this->product(2, 220);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->payload($productId, [
                'option1_name' => 'Size',
                'option2_name' => 'Colour',
                'option1_values' => ['Small', 'Large'],
                'option2_values' => ['Red'],
                'combinations' => [
                    ['opt1' => 'Small', 'opt2' => 'Red', 'sku' => 'S-R', 'image' => '',
                     'quantity' => 1, 'absolute_price' => 1000, 'cost_amount' => 500],
                    ['opt1' => 'Large', 'opt2' => 'Red', 'sku' => 'L-R', 'image' => '',
                     'quantity' => 2, 'absolute_price' => 2000, 'cost_amount' => 500],
                ],
            ]))->assertRedirect();

        $rows = DB::table('product_option_combinations')
            ->where('product_id', $productId)->get()->keyBy('sku');

        $this->assertEqualsWithDelta(740, (float) $rows['S-R']->absolute_cost, 0.0001);
        $this->assertEqualsWithDelta(760, (float) $rows['L-R']->absolute_cost, 0.0001);
    }

    /**
     * With no percentage and no additional the amount IS the unit cost, which
     * is most of this catalogue. Nothing may be added to it, and the grid
     * must not start printing a second number that restates the first.
     */
    public function test_with_no_percentage_and_no_additional_the_amount_is_the_unit_cost(): void
    {
        $productId = $this->product(0, 0);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", array_merge(
                $this->payload($productId, [
                    'option_name' => 'Size',
                    'values' => [
                        ['name' => 'Small', 'sku' => 'S', 'image' => '', 'quantity' => 3,
                         'absolute_price' => 1000, 'cost_amount' => 500],
                    ],
                ]),
                ['cost_percentage' => 0, 'cost_additional' => 0]
            ))->assertRedirect();

        $row = DB::table('product_option_combinations')->where('product_id', $productId)->first();

        $this->assertEqualsWithDelta(500, (float) $row->cost_amount, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $row->absolute_cost, 0.0001);
    }

    /**
     * The amount survives a save that does not touch it. The single-axis path
     * deletes every combination and re-inserts, so a value the form did not
     * send back would be wiped by the next save of any unrelated field.
     */
    public function test_the_amount_round_trips_through_the_edit_form(): void
    {
        $productId = $this->product(2, 220, ['Small']);
        $operator = $this->operator();

        $payload = $this->payload($productId, [
            'option_name' => 'Size',
            'values' => [
                ['name' => 'Small', 'sku' => 'S', 'image' => '', 'quantity' => 3,
                 'absolute_price' => 1000, 'cost_amount' => 500],
            ],
        ]);

        $this->actingAs($operator)->put("/catalog/products/{$productId}", $payload)->assertRedirect();

        // The edit form has to hand the AMOUNT back, not the total. Handing
        // back 740 would make every reopen-and-save inflate the cost by 240.
        $this->actingAs($operator)
            ->get("/catalog/products/{$productId}/edit")
            ->assertOk()
            ->assertSee('"cost_amount":500', false);

        $this->actingAs($operator)->put("/catalog/products/{$productId}", $payload)->assertRedirect();

        $row = DB::table('product_option_combinations')->where('product_id', $productId)->first();
        $this->assertEqualsWithDelta(500, (float) $row->cost_amount, 0.0001);
        $this->assertEqualsWithDelta(740, (float) $row->absolute_cost, 0.0001);
    }

    /**
     * A payload that predates the equation still lands somewhere sensible.
     * The one number it carried was the cost, and the cost is now the amount.
     */
    public function test_an_older_payload_sending_absolute_cost_is_read_as_the_amount(): void
    {
        $productId = $this->product(0, 0, ['Small']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", array_merge(
                $this->payload($productId, [
                    'option_name' => 'Size',
                    'values' => [
                        ['name' => 'Small', 'sku' => 'S', 'image' => '', 'quantity' => 3,
                         'absolute_price' => 1000, 'absolute_cost' => 640],
                    ],
                ]),
                ['cost_percentage' => 0, 'cost_additional' => 0]
            ))->assertRedirect();

        $row = DB::table('product_option_combinations')->where('product_id', $productId)->first();
        $this->assertEqualsWithDelta(640, (float) $row->cost_amount, 0.0001);
        $this->assertEqualsWithDelta(640, (float) $row->absolute_cost, 0.0001);
    }

    /**
     * THE GRID HAS TO PRINT THE WORKED-OUT FIGURE.
     *
     * Storing the right number is half of it. The reason the operator can be
     * shown a cost that differs from what they typed - and the reason the
     * back-fill of existing rows is safe - is that the total is on screen
     * before anybody presses Save. A source check, because the cell is built
     * by script and there is no page to assert against.
     */
    public function test_the_grid_prints_the_unit_cost_beside_the_amount(): void
    {
        $source = File::get(resource_path('views/catalog/products/partials/options_fields.blade.php'));

        $this->assertStringContainsString('function variationUnitCost(', $source,
            'the grid must work out the unit cost, not just collect an amount');
        $this->assertStringContainsString('js-unitcost', $source,
            'the worked-out figure needs somewhere to be drawn');
        $this->assertStringContainsString("['cost_percentage', 'cost_additional']", $source,
            'it must read the parent formula off the pricing section rather than assume it');

        // Bound to the parent fields, which are editable on the create form.
        // Without this the note and every row would fix themselves at page
        // load and go stale the moment either field changed.
        $this->assertStringContainsString('refreshCostFormula', $source);
    }
}
