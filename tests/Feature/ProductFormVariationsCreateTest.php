<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFormVariationsCreateTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->artisan('permissions:sync-catalogue');
        $group = UserGroup::create(['name' => 'Form variations desk']);
        $group->permissions()->attach(Permission::query()->where('key', 'like', '%catalog%')->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function payload(array $overrides): array
    {
        return array_merge([
            'name' => 'Form Tee', 'sku' => 'FORM-TEE', 'price' => '500', 'status' => '1',
            'quantity' => '999',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'cost_amount' => '100', 'cost_percentage' => '10', 'cost_additional' => '5',
            'description' => 'A plain description.',
            'images_json' => '["catalog/demo.jpg"]',
            '_options_format' => 'absolute',
        ], $overrides);
    }

    private function productId(string $sku): int
    {
        return (int) DB::table((string) config('catalog.prefix') . 'product')->where('sku', $sku)->value('product_id');
    }

    public function test_the_form_saves_a_product_without_a_photo(): void
    {
        $this->actingAs($this->manager())->post(route('products.store'), $this->payload([
            'sku' => 'NOPIC-1', 'images_json' => '[]',
        ]))->assertSessionHasNoErrors();

        $image = \Illuminate\Support\Facades\DB::table((string) config('catalog.prefix') . 'product')
            ->where('product_id', $this->productId('NOPIC-1'))->value('image');
        $this->assertSame('', (string) $image);
    }

    public function test_the_form_saves_one_variation_type_as_before(): void
    {
        $this->actingAs($this->manager())->post(route('products.store'), $this->payload([
            'option_name' => 'Size',
            'values' => [
                ['name' => 'Small', 'sku' => 'FORM-TEE-S', 'quantity' => '4', 'absolute_price' => '500',
                 'cost_amount' => '200', 'image' => 'catalog/small.jpg'],
                ['name' => 'Large', 'sku' => 'FORM-TEE-L', 'quantity' => '6', 'absolute_price' => '600',
                 'cost_amount' => '250', 'cost_additional' => '', 'status' => '0'],
            ],
        ]))->assertRedirect(route('products.index'))->assertSessionHasNoErrors();

        $p = (string) config('catalog.prefix');
        $id = $this->productId('FORM-TEE');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(10, (int) DB::table($p . 'product')->where('product_id', $id)->value('quantity'));

        $povs = DB::table($p . 'product_option_value')->where('product_id', $id)->get()->keyBy('sku');
        $this->assertCount(2, $povs);
        $this->assertSame(4, (int) $povs['FORM-TEE-S']->quantity);
        $this->assertEqualsWithDelta(600, (float) $povs['FORM-TEE-L']->absolute_price, 0.0001);
        $this->assertEqualsWithDelta(255, (float) $povs['FORM-TEE-S']->absolute_cost, 0.0001);
        $this->assertEqualsWithDelta(315, (float) $povs['FORM-TEE-L']->absolute_cost, 0.0001);
        $this->assertEqualsWithDelta(10, (float) $povs['FORM-TEE-L']->cost_percentage, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $povs['FORM-TEE-L']->cost_additional, 0.0001, 'a blank additional is the parent\'s');

        $combos = DB::table('product_option_combinations')->where('product_id', $id)->orderBy('sort_order')->get();
        $this->assertSame(['FORM-TEE-S', 'FORM-TEE-L'], $combos->pluck('sku')->all());
        $this->assertSame('catalog/small.jpg', $combos[0]->image);
        $this->assertNull($combos[1]->image);
        $this->assertSame([1, 0], $combos->pluck('status')->map(fn ($s) => (int) $s)->all());
        $this->assertEqualsWithDelta(255, (float) $combos[0]->absolute_cost, 0.0001);
        foreach ($combos as $combo) {
            $this->assertSame(1, DB::table('product_option_combination_values')->where('combination_id', $combo->id)->count());
        }

        $optionId = (int) $povs['FORM-TEE-S']->option_id;
        $this->assertSame('Size', DB::table($p . 'option_description')->where('option_id', $optionId)->value('name'));
    }

    public function test_the_form_saves_two_variation_types_as_before(): void
    {
        $row = fn (string $a, string $b, string $sku, string $qty, string $price) => [
            'opt1' => $a, 'opt2' => $b, 'sku' => $sku, 'quantity' => $qty, 'absolute_price' => $price, 'cost_amount' => '200',
        ];

        $this->actingAs($this->manager())->post(route('products.store'), $this->payload([
            'option1_name' => 'Size', 'option2_name' => 'Colour',
            'option1_values' => ['S', 'L'], 'option2_values' => ['Red', 'Blue'],
            'combinations' => [
                $row('S', 'Red', 'FT-S-R', '1', '500') + ['image' => 'catalog/s-red.jpg'],
                $row('S', 'Blue', 'FT-S-B', '2', '500'),
                $row('L', 'Red', 'FT-L-R', '3', '600') + ['status' => '0'],
                $row('L', 'Blue', 'FT-L-B', '4', '600') + ['cost_additional' => '0'],
            ],
        ]))->assertRedirect(route('products.index'))->assertSessionHasNoErrors();

        $p = (string) config('catalog.prefix');
        $id = $this->productId('FORM-TEE');
        $this->assertSame(10, (int) DB::table($p . 'product')->where('product_id', $id)->value('quantity'));

        $combos = DB::table('product_option_combinations')->where('product_id', $id)->orderBy('sort_order')->get()->keyBy('sku');
        $this->assertSame(['FT-S-R', 'FT-S-B', 'FT-L-R', 'FT-L-B'], $combos->keys()->all());
        $this->assertSame('catalog/s-red.jpg', $combos['FT-S-R']->image);
        $this->assertSame(0, (int) $combos['FT-L-R']->status);
        $this->assertEqualsWithDelta(255, (float) $combos['FT-S-B']->absolute_cost, 0.0001);
        $this->assertEqualsWithDelta(260, (float) $combos['FT-L-B']->absolute_cost, 0.0001, 'a typed 0 additional is a real 0');
        foreach ($combos as $combo) {
            $this->assertSame(2, DB::table('product_option_combination_values')->where('combination_id', $combo->id)->count());
        }

        $povs = DB::table($p . 'product_option_value')->where('product_id', $id)->get();
        $this->assertCount(4, $povs);
        $this->assertSame([''], $povs->pluck('sku')->unique()->values()->all());
    }

    public function test_the_form_refuses_a_duplicate_variation_sku_before_anything_is_saved(): void
    {
        $this->actingAs($this->manager())->post(route('products.store'), $this->payload([
            'sku' => 'FORM-TEE-2',
            'option_name' => 'Size',
            'values' => [
                ['name' => 'Small', 'sku' => 'FORM-DUP', 'quantity' => '1', 'absolute_price' => '500'],
                ['name' => 'Large', 'sku' => 'form-dup', 'quantity' => '1', 'absolute_price' => '500'],
            ],
        ]))->assertSessionHasErrors('option_sku');

        $this->assertSame('Duplicate SKU "form-dup" within this product\'s options.', session('errors')->first('option_sku'));
        $this->assertSame(0, $this->productId('FORM-TEE-2'), 'nothing was created');
    }
}
