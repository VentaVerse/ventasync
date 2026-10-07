<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FailedSaveKeepsTypingTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->artisan('permissions:sync-catalogue');
        $group = UserGroup::create(['name' => 'Catalog desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_catalog/product', 'view_catalog/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku, string $name): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Edited product', 'model' => 'KEEP-1', 'sku' => 'KEEP-1',
            'price' => '250.00', 'quantity' => '17', 'status' => '1',
            'weight' => '1', 'length' => '10', 'width' => '10', 'height' => '10',
            'manufacturer_id' => '0', 'images_json' => '["catalog/demo.jpg"]',
            'description' => 'A dependable test product description that comfortably clears the eighty character floor the marketplaces set.',
            '_options_format' => 'absolute',
        ], $overrides);
    }

    public function test_the_typed_variations_come_back_after_a_refused_save(): void
    {
        $other = $this->seedProduct('TAKEN-SKU', 'The product already holding it');
        $mine = $this->seedProduct('KEEP-1', 'Edited product');
        $user = $this->manager();

        $editUrl = route('products.edit', $mine);
        $r = $this->actingAs($user)->from($editUrl)->put(route('products.update', $mine), $this->payload([
            'option_name' => 'Pack size',
            'values' => [
                ['name' => '1 pc', 'sku' => 'KEEP-1PC', 'quantity' => '4', 'absolute_price' => '120', 'status' => '1', 'cost_amount' => '55', 'cost_additional' => '2'],
                ['name' => '5 pcs', 'sku' => 'TAKEN-SKU', 'quantity' => '9', 'absolute_price' => '500', 'status' => '1', 'cost_amount' => '200', 'cost_additional' => '0'],
            ],
        ]));

        $r->assertRedirect($editUrl);
        $r->assertSessionHasErrors('option_sku');

        $page = $this->actingAs($user)->get($editUrl)->assertOk()->getContent();

        $this->assertStringContainsString('"name":"5 pcs"', $page);
        $this->assertStringContainsString('"sku":"TAKEN-SKU"', $page);
        $this->assertStringContainsString('"quantity":"9"', $page);
        $this->assertStringContainsString('"absolute_price":"500"', $page);
        $this->assertStringContainsString('"cost_amount":"200"', $page);
        $this->assertStringContainsString('"sku":"KEEP-1PC"', $page);
    }

    public function test_the_reason_names_the_product_holding_the_sku(): void
    {
        $this->seedProduct('TAKEN-SKU', 'The product already holding it');
        $mine = $this->seedProduct('KEEP-2', 'Edited product');
        $user = $this->manager();

        $this->actingAs($user)->from(route('products.edit', $mine))
            ->put(route('products.update', $mine), $this->payload([
                'model' => 'KEEP-2', 'sku' => 'KEEP-2',
                'option_name' => 'Pack size',
                'values' => [['name' => '1 pc', 'sku' => 'TAKEN-SKU', 'quantity' => '1', 'absolute_price' => '10', 'status' => '1']],
            ]));

        $messages = session('errors')->get('option_sku');
        $this->assertStringContainsString('The product already holding it', implode(' ', $messages),
            '"another product" sends the operator searching; the name lets them decide');
    }

    public function test_two_axis_typing_comes_back_too(): void
    {
        $mine = $this->seedProduct('KEEP-3', 'Edited product');
        $user = $this->manager();
        $editUrl = route('products.edit', $mine);

        $this->actingAs($user)->from($editUrl)->put(route('products.update', $mine), $this->payload([
            'model' => 'KEEP-3', 'sku' => 'KEEP-3',
            'option1_name' => 'Colour', 'option2_name' => 'Size',
            'option1_values' => ['Red', 'Blue'], 'option2_values' => ['S'],
            'combinations' => [
                ['opt1' => 'Red', 'opt2' => 'S', 'sku' => 'KEEP-3', 'quantity' => '2', 'absolute_price' => '90', 'status' => '1'],
                ['opt1' => 'Blue', 'opt2' => 'S', 'sku' => 'CB-2', 'quantity' => '3', 'absolute_price' => '95', 'status' => '1'],
            ],
        ]))->assertSessionHasErrors('option_sku');

        $page = $this->actingAs($user)->get($editUrl)->assertOk()->getContent();
        $this->assertStringContainsString('"opt1_name":"Blue"', $page);
        $this->assertStringContainsString('"sku":"CB-2"', $page);
        $this->assertStringContainsString('"quantity":"3"', $page);
        $this->assertStringContainsString('"option_name":"Colour"', $page);
    }

    public function test_a_successful_save_still_round_trips_through_the_database(): void
    {
        $mine = $this->seedProduct('KEEP-4', 'Edited product');
        $user = $this->manager();
        $editUrl = route('products.edit', $mine);

        $this->actingAs($user)->from($editUrl)->put(route('products.update', $mine), $this->payload([
            'model' => 'KEEP-4', 'sku' => 'KEEP-4',
            'option_name' => 'Pack size',
            'values' => [['name' => '1 pc', 'sku' => 'KEEP-4PC', 'quantity' => '4', 'absolute_price' => '120', 'status' => '1']],
        ]))->assertSessionHasNoErrors();

        $page = $this->actingAs($user)->get($editUrl)->assertOk()->getContent();
        $this->assertStringContainsString('KEEP-4PC', $page);
        $this->assertStringContainsString('Pack size', $page);
    }
}
