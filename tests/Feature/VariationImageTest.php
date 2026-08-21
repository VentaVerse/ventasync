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
}
