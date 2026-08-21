<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A picture for the thing that is actually sold.
 *
 * The image lives on product_option_combinations, not on
 * product_option_value, and that is the whole design decision. A combination
 * is the sellable unit - it carries the sku, the quantity and the price, and
 * it maps one-to-one onto a marketplace variant. An option value is one axis
 * of one: "Small", or "Red".
 *
 * On a Size x Colour product, storing the image against the option value can
 * say what "Small" looks like and what "Red" looks like, but never what
 * "Small / Red" looks like - and Small/Red is what the marketplace sells and
 * what the packer pulls off the shelf. Apparel is the case that most wants a
 * picture per variation and the case that storage cannot serve.
 *
 * Saving a two-axis product DELETES its combinations and re-inserts them, so
 * the image has to round-trip through the form like sku and quantity rather
 * than being held server-side by identity. That is what the two-axis test
 * below is really guarding.
 */
class VariationImageTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $group = UserGroup::create(['name' => 'Catalogue']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_products', 'view_products'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * @return array{0: int, 1: string, 2: array<int, string>}
     */
    private function productWithOneAxis(array $valueNames): array
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-VIMG', 'sku' => 'SKU-VIMG', 'quantity' => 0,
            'price' => 100.0000, 'status' => 1, 'image' => 'catalog/parent.jpg',
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId, 'name' => 'Image Tee',
            'description' => '', 'tag' => '', 'meta_title' => 'Image Tee',
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

        return [$productId, 'Size', $valueNames];
    }

    public function test_a_single_axis_variation_keeps_the_image_it_was_given(): void
    {
        [$productId, $optionName, $names] = $this->productWithOneAxis(['Small', 'Large']);

        $response = $this->actingAs($this->operator())
            ->post("/catalog/products/{$productId}/options", [
                'option_name' => $optionName,
                'values' => [
                    ['name' => 'Small', 'sku' => 'S', 'image' => 'catalog/small.jpg',
                     'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
                    // No image: this variation inherits the product's.
                    ['name' => 'Large', 'sku' => 'L', 'image' => '',
                     'quantity' => 5, 'absolute_price' => 130, 'absolute_cost' => 0],
                ],
            ]);

        $response->assertRedirect();

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)->orderBy('sort_order')->get();

        $this->assertCount(2, $combos);
        $this->assertSame('catalog/small.jpg', $combos[0]->image);

        // Empty is stored as NULL, so "inherit the product image" is one
        // value rather than two that mean the same thing.
        $this->assertNull($combos[1]->image,
            'a variation with no image of its own must store null, not an empty string');
    }

    /**
     * The read path has to bring it back, or the next save writes it away
     * again. Single-axis rows come from product_option_value, so the image
     * has to be carried across the join from the combination that owns it.
     */
    public function test_the_edit_form_shows_the_image_back(): void
    {
        [$productId, $optionName] = $this->productWithOneAxis(['Small']);

        // One operator, reused: the group name is unique, so building a
        // second would collide before reaching anything worth asserting.
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post("/catalog/products/{$productId}/options", [
                'option_name' => $optionName,
                'values' => [
                    ['name' => 'Small', 'sku' => 'S', 'image' => 'catalog/small.jpg',
                     'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
                ],
            ])->assertRedirect();

        $this->actingAs($operator)
            ->get("/catalog/products/{$productId}/edit")
            ->assertOk()
            ->assertSee('catalog\/small.jpg', false);
    }

    /**
     * THE CASE THE WHOLE DESIGN IS FOR. Two axes, so a combination joins two
     * option values and the image cannot belong to either of them alone.
     *
     * Also the case that proves the round trip: this save path deletes every
     * combination for the product and re-inserts, so an image the form did
     * not send back would be silently dropped.
     */
    public function test_a_two_axis_combination_keeps_its_own_image(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $response = $this->actingAs($this->operator())
            ->post("/catalog/products/{$productId}/options", [
                'option1_name' => 'Size',
                'option2_name' => 'Colour',
                'option1_values' => ['Small', 'Large'],
                'option2_values' => ['Red', 'Blue'],
                'combinations' => [
                    ['opt1' => 'Small', 'opt2' => 'Red', 'sku' => 'S-R',
                     'image' => 'catalog/small-red.jpg', 'quantity' => 1,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                    ['opt1' => 'Small', 'opt2' => 'Blue', 'sku' => 'S-B',
                     'image' => 'catalog/small-blue.jpg', 'quantity' => 2,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                    ['opt1' => 'Large', 'opt2' => 'Red', 'sku' => 'L-R',
                     'image' => '', 'quantity' => 3,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                    ['opt1' => 'Large', 'opt2' => 'Blue', 'sku' => 'L-B',
                     'image' => 'catalog/large-blue.jpg', 'quantity' => 4,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                ],
            ]);

        $response->assertRedirect();

        $bySku = DB::table('product_option_combinations')
            ->where('product_id', $productId)->pluck('image', 'sku');

        // Small/Red and Small/Blue share the option value "Small" and carry
        // DIFFERENT images. That is the assertion the option-value design
        // could not have satisfied.
        $this->assertSame('catalog/small-red.jpg', $bySku['S-R']);
        $this->assertSame('catalog/small-blue.jpg', $bySku['S-B']);
        $this->assertNotSame($bySku['S-R'], $bySku['S-B']);

        $this->assertNull($bySku['L-R']);
        $this->assertSame('catalog/large-blue.jpg', $bySku['L-B']);
    }

    /**
     * THE PATH THE FORM ACTUALLY TAKES.
     *
     * The edit page posts to products.update, not to products.options.update,
     * and ProductController has its own combination-writing code entirely
     * separate from ProductOptionController's. The first version of this test
     * file exercised the options endpoint, passed, and shipped a feature that
     * did nothing when a person pressed Save changes - because the save they
     * press goes somewhere else.
     *
     * Assert on the endpoint the button posts to, not the one that looks like
     * it owns the concept.
     */
    private function productFormPayload(int $productId, array $values): array
    {
        return [
            'name' => 'Image Tee',
            'model' => 'MDL-VIMG',
            'sku' => 'SKU-VIMG',
            'price' => 100,
            'quantity' => 0,
            'status' => 1,
            // The hidden field the variations partial ships. Without it the
            // controller falls through to the legacy option path and the
            // whole absolute-options branch never runs - which is a trap
            // worth encoding, since a payload missing it looks perfectly
            // reasonable and silently tests nothing.
            '_options_format' => 'absolute',
            'option_name' => 'Size',
            'values' => $values,
        ];
    }

    public function test_the_products_update_endpoint_saves_the_variation_image(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $response = $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->productFormPayload($productId, [
                ['name' => 'Small', 'sku' => 'S', 'image' => 'catalog/small.jpg',
                 'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
                ['name' => 'Large', 'sku' => 'L', 'image' => '',
                 'quantity' => 5, 'absolute_price' => 130, 'absolute_cost' => 0],
            ]));

        $response->assertRedirect();

        $bySku = DB::table('product_option_combinations')
            ->where('product_id', $productId)->pluck('image', 'sku');

        $this->assertSame('catalog/small.jpg', $bySku['S'],
            'the image must survive the save the form actually performs');
        $this->assertNull($bySku['L']);
    }

    /**
     * And it must survive a SECOND save that does not change it. This path
     * rebuilds every combination from product_option_value rows, which do not
     * carry the image - so without carrying it across, the next save of any
     * unrelated field would silently wipe it.
     */
    public function test_a_later_save_does_not_wipe_an_image_it_was_given_again(): void
    {
        [$productId] = $this->productWithOneAxis(['Small']);
        $operator = $this->operator();

        $payload = $this->productFormPayload($productId, [
            ['name' => 'Small', 'sku' => 'S', 'image' => 'catalog/small.jpg',
             'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
        ]);

        $this->actingAs($operator)->put("/catalog/products/{$productId}", $payload)->assertRedirect();
        $this->actingAs($operator)->put("/catalog/products/{$productId}", $payload)->assertRedirect();

        $this->assertSame('catalog/small.jpg', DB::table('product_option_combinations')
            ->where('product_id', $productId)->value('image'));
    }

    /**
     * The two-axis branch of the same endpoint. Different method
     * (saveAbsoluteTwoOptions), different write site, so it needs its own
     * assertion rather than being assumed to follow.
     */
    public function test_the_products_update_endpoint_saves_a_two_axis_image(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", [
                'name' => 'Image Tee', 'model' => 'MDL-VIMG', 'sku' => 'SKU-VIMG',
                'price' => 100, 'quantity' => 0, 'status' => 1,
                '_options_format' => 'absolute',
                'option1_name' => 'Size',
                'option2_name' => 'Colour',
                'option1_values' => ['Small', 'Large'],
                'option2_values' => ['Red', 'Blue'],
                'combinations' => [
                    ['opt1' => 'Small', 'opt2' => 'Red', 'sku' => 'S-R',
                     'image' => 'catalog/small-red.jpg', 'quantity' => 1,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                    ['opt1' => 'Small', 'opt2' => 'Blue', 'sku' => 'S-B',
                     'image' => 'catalog/small-blue.jpg', 'quantity' => 2,
                     'absolute_price' => 100, 'absolute_cost' => 0],
                ],
            ])->assertRedirect();

        $bySku = DB::table('product_option_combinations')
            ->where('product_id', $productId)->pluck('image', 'sku');

        // Same option value "Small", two different pictures. This is the
        // assertion the option-value storage design could never have met.
        $this->assertSame('catalog/small-red.jpg', $bySku['S-R']);
        $this->assertSame('catalog/small-blue.jpg', $bySku['S-B']);
    }

    /**
     * A variation can be withdrawn from sale on its own.
     *
     * A product could be turned off as a whole and nothing smaller, so a
     * discontinued size had to be deleted - destroying its SKU and history -
     * or zeroed, which says "temporarily out" rather than "not sold any
     * more".
     */
    public function test_a_variation_can_be_disabled_on_its_own(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->productFormPayload($productId, [
                ['name' => 'Small', 'sku' => 'S', 'status' => 0,
                 'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
                ['name' => 'Large', 'sku' => 'L', 'status' => 1,
                 'quantity' => 5, 'absolute_price' => 130, 'absolute_cost' => 0],
            ]))->assertRedirect();

        $bySku = DB::table('product_option_combinations')
            ->where('product_id', $productId)->pluck('status', 'sku');

        $this->assertSame(0, (int) $bySku['S']);
        $this->assertSame(1, (int) $bySku['L']);
    }

    /**
     * DISABLING DOES NOT TOUCH STOCK. On-hand is a fact about a shelf, and a
     * withdrawn variation whose units are still in the building is still
     * stock somebody has to account for. The parent total keeps counting it.
     */
    public function test_disabling_a_variation_does_not_change_the_stock_it_holds(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->productFormPayload($productId, [
                ['name' => 'Small', 'sku' => 'S', 'status' => 0,
                 'quantity' => 3, 'absolute_price' => 120, 'absolute_cost' => 0],
                ['name' => 'Large', 'sku' => 'L', 'status' => 1,
                 'quantity' => 5, 'absolute_price' => 130, 'absolute_cost' => 0],
            ]))->assertRedirect();

        $this->assertSame(3, (int) DB::table('product_option_combinations')
            ->where('product_id', $productId)->where('sku', 'S')->value('quantity'));

        $this->assertSame(8, (int) DB::table(config('catalog.prefix').'product')
            ->where('product_id', $productId)->value('quantity'),
            'the parent total still counts a disabled variation');
    }

    /**
     * A payload that says nothing about status must not switch anything off.
     * Every variation in the database predates this column.
     */
    public function test_a_payload_without_status_leaves_a_variation_enabled(): void
    {
        [$productId] = $this->productWithOneAxis(['Small']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", $this->productFormPayload($productId, [
                ['name' => 'Small', 'sku' => 'S', 'quantity' => 1,
                 'absolute_price' => 100, 'absolute_cost' => 0],
            ]))->assertRedirect();

        $this->assertSame(1, (int) DB::table('product_option_combinations')
            ->where('product_id', $productId)->value('status'));
    }

    /** The two-axis branch persists it too - a different method entirely. */
    public function test_a_two_axis_combination_can_be_disabled(): void
    {
        [$productId] = $this->productWithOneAxis(['Small', 'Large']);

        $this->actingAs($this->operator())
            ->put("/catalog/products/{$productId}", [
                'name' => 'Image Tee', 'model' => 'MDL-VIMG', 'sku' => 'SKU-VIMG',
                'price' => 100, 'quantity' => 0, 'status' => 1,
                '_options_format' => 'absolute',
                'option1_name' => 'Size', 'option2_name' => 'Colour',
                'option1_values' => ['Small'], 'option2_values' => ['Red', 'Blue'],
                'combinations' => [
                    ['opt1' => 'Small', 'opt2' => 'Red', 'sku' => 'S-R', 'status' => 0,
                     'quantity' => 1, 'absolute_price' => 100, 'absolute_cost' => 0],
                    ['opt1' => 'Small', 'opt2' => 'Blue', 'sku' => 'S-B', 'status' => 1,
                     'quantity' => 2, 'absolute_price' => 100, 'absolute_cost' => 0],
                ],
            ])->assertRedirect();

        $bySku = DB::table('product_option_combinations')
            ->where('product_id', $productId)->pluck('status', 'sku');

        // Withdrawing Small/Red must not withdraw Small/Blue.
        $this->assertSame(0, (int) $bySku['S-R']);
        $this->assertSame(1, (int) $bySku['S-B']);
    }
}
