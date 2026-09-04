<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Weight, dimensions and a real description are required on EVERY core
 * product save (#198, extended by the owner 2026-09-04: "the actual goal is
 * to prevent errors" - a product that cannot list on the Big 3 is not
 * saved half-made and repaired at push time). The 80-character
 * description floor is the strictest marketplace bar (TikTok's listing
 * quality floor; Shopee's hard minimum is ~25; Lazada publishes none),
 * counted on the text with the HTML stripped. The 2026-09 change
 * reverses #198's edit carve-out: an old zero-parcel row must be
 * completed the next time anyone touches it.
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
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
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
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_old_zero_parcel_row_must_be_completed_on_edit(): void
    {
        $pid = $this->seedProduct('OLD-ZERO', 0);
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('products.edit', $pid))->assertOk()->getContent();
        $this->assertStringContainsString('This product has no complete parcel', $page);

        // The 2026-09-04 reversal of #198's carve-out: an edit is exactly
        // the moment the owner is looking at the product, so the zero
        // parcel does not survive it.
        $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Renamed, parcel untouched', 'model' => 'OLD-ZERO', 'sku' => 'OLD-ZERO',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0',
            'manufacturer_id' => '0', 'images_json' => '[]',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ])->assertSessionHasErrors(['weight', 'length', 'width', 'height']);

        $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Renamed, parcel filled', 'model' => 'OLD-ZERO', 'sku' => 'OLD-ZERO',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            'manufacturer_id' => '0', 'images_json' => '[]',
            'description' => 'A well-made product description with easily more than the eighty characters of text the strictest marketplace demands.',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_description_shorter_than_the_strictest_bar_is_refused(): void
    {
        $r = $this->actingAs($this->manager())->post(route('products.store'), [
            'name' => 'Terse newborn', 'price' => '100', 'status' => '1',
            'weight' => '0.5', 'length' => '10', 'width' => '5', 'height' => '3',
            // 26 chars of text in a lot of markup: passes Shopee's 25,
            // fails TikTok's 80, and the tags must not count.
            'description' => '<p><strong>Nice guitar,</strong> plays well.</p>',
        ]);

        $r->assertSessionHasErrors(['description']);
        $this->assertStringContainsString('80 characters', session('errors')->first('description'));
        $this->assertStringContainsString('TikTok', session('errors')->first('description'),
            'the refusal names WHICH marketplace sets the bar');
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
