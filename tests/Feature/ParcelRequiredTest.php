<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParcelRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->artisan('permissions:sync-catalogue');
        $group = UserGroup::create(['name' => 'Parcel desk']);
        $group->permissions()->attach(Permission::query()->where('key', 'like', '%catalog%')->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku, float $weight): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => '', 'weight' => $weight, 'length' => $weight > 0 ? 10 : 0, 'width' => $weight > 0 ? 10 : 0, 'height' => $weight > 0 ? 10 : 0,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Parcel product ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_a_new_product_cannot_be_born_without_a_parcel(): void
    {
        $r = $this->actingAs($this->manager())->post(route('products.store'), [
            'name' => 'Parcel-less newborn', 'price' => '100', 'status' => '1',
            'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ]);

        $r->assertSessionHasErrors(['weight', 'length', 'width', 'height']);
        $this->assertStringContainsString('more than zero', session('errors')->first('weight'));
        $this->assertNull(DB::table((string) config('catalog.prefix') . 'product')->where('price', 100)->first());
    }

    public function test_a_new_product_with_a_parcel_is_born_fine(): void
    {
        $this->actingAs($this->manager())->post(route('products.store'), [
            'name' => 'Well-parcelled newborn', 'sku' => 'WPN-1', 'price' => '100', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
            'images_json' => '["catalog/demo.jpg"]',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_old_zero_parcel_row_must_be_completed_on_edit(): void
    {
        $pid = $this->seedProduct('OLD-ZERO', 0);
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('products.edit', $pid))->assertOk()->getContent();
        $this->assertStringContainsString('This product is missing its weight or size', $page);

        $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Renamed, parcel untouched', 'model' => 'OLD-ZERO', 'sku' => 'OLD-ZERO',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0',
            'manufacturer_id' => '0', 'images_json' => '["catalog/demo.jpg"]',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ])->assertSessionHasErrors(['weight', 'length', 'width', 'height']);

        $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Renamed, parcel filled', 'model' => 'OLD-ZERO', 'sku' => 'OLD-ZERO',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'manufacturer_id' => '0', 'images_json' => '["catalog/demo.jpg"]',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_terse_description_is_accepted_and_the_parcel_still_rules(): void
    {
        $r = $this->actingAs($this->manager())->post('/catalog/products', [
            'name' => 'Terse newborn', 'sku' => 'PRT-3', 'price' => '100', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'images_json' => '["catalog/demo.jpg"]',
            'description' => '<p><strong>Nice guitar,</strong> plays well.</p>',
        ]);

        $r->assertSessionDoesntHaveErrors(['description']);
    }
}
