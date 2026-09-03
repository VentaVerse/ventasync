<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Weight and dimensions become required on the core product (#198,
 * the owner: "This will prevent issues with the weight and dimensions when
 * pushing."). New products hard-require the parcel; existing rows with
 * zeros keep saving - a save is never held hostage to fields nobody
 * touched (#185's lesson) - and the Missing-parcel filter is where
 * that debt gets worked down.
 */
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
        ]);

        $r->assertSessionHasErrors(['weight', 'length', 'width', 'height']);
        $this->assertStringContainsString('the channels refuse',
            session('errors')->first('weight'), 'the requirement names WHY, not just that');
        $this->assertNull(DB::table((string) config('catalog.prefix') . 'product')->where('price', 100)->first());
    }

    public function test_a_new_product_with_a_parcel_is_born_fine(): void
    {
        $this->actingAs($this->manager())->post(route('products.store'), [
            'name' => 'Well-parcelled newborn', 'price' => '100', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_old_zero_parcel_row_still_saves_but_wears_the_warning(): void
    {
        $pid = $this->seedProduct('OLD-ZERO', 0);
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('products.edit', $pid))->assertOk()->getContent();
        $this->assertStringContainsString('This product has no complete parcel', $page);

        $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Renamed, parcel untouched', 'model' => 'OLD-ZERO', 'sku' => 'OLD-ZERO',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0',
            'manufacturer_id' => '0', 'images_json' => '[]',
        ])->assertSessionHasNoErrors();
    }

    public function test_the_missing_parcel_filter_finds_the_debt(): void
    {
        $this->seedProduct('HAS-PARCEL', 1.0);
        $this->seedProduct('NO-PARCEL', 0);

        $page = $this->actingAs($this->manager())
            ->get(route('products.index', ['status' => 'no_parcel']))->assertOk()->getContent();

        $this->assertStringContainsString('NO-PARCEL', $page);
        $this->assertStringNotContainsString('HAS-PARCEL', $page);
    }
}
