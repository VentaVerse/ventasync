<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The push-review modal's wires (cleanup 7+9, the owner-approved draft
 * 2026-09-03): the single review says exactly what goes up - price WITH
 * its arithmetic, names not ids - the bulk review leads with the verdict
 * and groups blockers BY GAP, and a fix writes only what answering on
 * each listing page would have written.
 */
class PushReviewModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'store_name' => 'Main', 'enabled' => true, 'mode' => 'live',
            'partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2,
            'access_token' => 't', 'refresh_token' => 'r', 'region' => 'ph',
        ]);
        ShopeeCategory::create(['category_id' => 100200, 'parent_id' => null, 'name' => 'Manual Brewers', 'level' => 0, 'leaf' => true]);
        ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100200], ['attributes' => []]);
        ShopeeLogistic::create(['logistics_channel_id' => 8003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Review modal managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => $langId,
            'name' => $name, 'description' => 'Catalog words.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_single_review_states_what_goes_up_in_names_and_arithmetic(): void
    {
        $pid = $this->seedProduct('Ceramic Dripper', 'CD-1', 300.0);
        ShopeeListing::create([
            'product_id' => $pid, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_fixed' => 70,
        ]);

        $json = $this->actingAs($this->manager())
            ->getJson(route('ext.shopee.products.push_review', $pid))
            ->assertOk()->json();

        $this->assertTrue($json['ready']);
        $this->assertSame('370.00', $json['price']);
        $this->assertSame('300.00', $json['core_price']);
        $this->assertStringContainsString('70.00 over catalog', $json['rule']);
        $this->assertSame('Manual Brewers', $json['category'], 'the category goes by NAME, not id');
        $this->assertSame(['J&T Express'], $json['couriers'], 'couriers go by name too');
        $this->assertSame('Ceramic Dripper', $json['title'], 'no override yet: the catalog name is what would go');
    }

    public function test_a_modal_edited_title_is_saved_as_the_override_before_the_push(): void
    {
        $pid = $this->seedProduct('Original name', 'ON-1');
        ShopeeListing::create(['product_id' => $pid, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);

        // The push itself will fail fast on missing credentials paths in
        // this bare setup, but the override write happens FIRST - the same
        // order Save and push uses, so a refusal never loses the typing.
        $this->actingAs($this->manager())->post(route('ext.shopee.products.push_direct', $pid), [
            'title' => 'Snappier Shopee name',
            'description' => 'Shopee-only words.',
        ]);

        $listing = ShopeeListing::query()->where('product_id', $pid)->firstOrFail();
        $this->assertSame('Snappier Shopee name', $listing->item_name);
        $this->assertSame('Shopee-only words.', $listing->description);
    }

    public function test_the_bulk_review_groups_blockers_by_gap_not_by_product(): void
    {
        $template = ShopeeCategoryTemplate::query()->where('category_id', 100200)->first();
        $template->update(['attributes' => ['attribute_list' => [
            ['attribute_id' => 1000, 'display_attribute_name' => 'Brand Origin', 'is_mandatory' => true],
        ]]]);

        // Two products missing the same attribute, one missing couriers,
        // one fully ready.
        $a = $this->seedProduct('Missing attr A', 'MA-1');
        $b = $this->seedProduct('Missing attr B', 'MA-2');
        $c = $this->seedProduct('Missing courier', 'MC-1');
        $d = $this->seedProduct('Ready', 'RD-1');
        foreach ([$a, $b] as $pid) {
            ShopeeListing::create(['product_id' => $pid, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);
        }
        ShopeeListing::create(['product_id' => $c, 'shopee_category_id' => 100200, 'logistic_ids' => [], 'attribute_values' => ['1000' => 'PH']]);
        ShopeeListing::create(['product_id' => $d, 'shopee_category_id' => 100200, 'logistic_ids' => [8003], 'attribute_values' => ['1000' => 'PH']]);

        $json = $this->actingAs($this->manager())
            ->getJson(route('ext.shopee.products.bulk_push_review', ['product_ids' => [$a, $b, $c, $d]]))
            ->assertOk()->json();

        $this->assertSame([$d], $json['ready']);
        $this->assertCount(1, $json['attribute_gaps'], 'one gap card per ATTRIBUTE, not per product');
        $this->assertSame('Brand Origin', $json['attribute_gaps'][0]['attribute']);
        $this->assertEqualsCanonicalizing([$a, $b], $json['attribute_gaps'][0]['product_ids']);
        $this->assertSame([$c], $json['courier_gap']['product_ids']);
        $this->assertNotEmpty($json['courier_gap']['logistics'], 'the fix control ships with its options');
    }

    public function test_the_bulk_review_offers_a_category_fixer_and_the_fix_sets_only_the_blank_ones(): void
    {
        $manager = $this->manager();
        // the owner, 2026-09-05: ten enclosures needing a category had only
        // "Open its listing" each; couriers had a fixer. Now both do.
        $a = $this->seedProduct('1590B Enclosure', 'EN-1');
        $b = $this->seedProduct('1590BB Enclosure', 'EN-2');
        $c = $this->seedProduct('Has a category', 'EN-3');
        ShopeeListing::create(['product_id' => $a, 'logistic_ids' => [8003]]);
        ShopeeListing::create(['product_id' => $b, 'logistic_ids' => [8003]]);
        ShopeeListing::create(['product_id' => $c, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);

        $json = $this->actingAs($manager)
            ->getJson(route('ext.shopee.products.bulk_push_review', ['product_ids' => [$a, $b, $c]]))
            ->assertOk()->json();

        $this->assertEqualsCanonicalizing([$a, $b], $json['category_gap']['product_ids'], 'one fixer over every product without a category');
        $this->assertSame(route('ext.shopee.products.searchCategories'), $json['category_gap']['search_url']);
        $this->assertTrue($json['category_gap']['fetched']);
        $this->assertCount(2, $json['category_gaps'], 'each product still named with its listing link');

        $this->actingAs($manager)->postJson(route('ext.shopee.products.bulk_fix'), [
            'kind' => 'category', 'category_id' => 100200, 'product_ids' => [$a, $b, $c],
        ])->assertOk()->assertJson(['written' => 2]);
        $this->assertSame(100200, (int) ShopeeListing::query()->where('product_id', $a)->value('shopee_category_id'));
        $this->assertSame(100200, (int) ShopeeListing::query()->where('product_id', $c)->value('shopee_category_id'), 'a category already chosen is never overwritten');

        $this->actingAs($manager)->postJson(route('ext.shopee.products.bulk_fix'), [
            'kind' => 'category', 'category_id' => 999999, 'product_ids' => [$a],
        ])->assertStatus(422);

        $after = $this->actingAs($manager)
            ->getJson(route('ext.shopee.products.bulk_push_review', ['product_ids' => [$a, $b, $c]]))
            ->assertOk()->json();
        $this->assertSame([], $after['category_gaps'], 'the gap closes once the category is set');
        $this->assertEqualsCanonicalizing([$a, $b, $c], $after['ready']);
    }

    public function test_a_bulk_fix_answers_only_the_products_missing_it(): void
    {
        $a = $this->seedProduct('Blank', 'BL-1');
        $b = $this->seedProduct('Already answered', 'AA-1');
        ShopeeListing::create(['product_id' => $a, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);
        ShopeeListing::create(['product_id' => $b, 'shopee_category_id' => 100200, 'logistic_ids' => [8003], 'attribute_values' => ['Brand Origin' => 'Japan']]);

        $this->actingAs($this->manager())->postJson(route('ext.shopee.products.bulk_fix'), [
            'kind' => 'attribute', 'attribute' => 'Brand Origin', 'value' => 'Philippines',
            'product_ids' => [$a, $b],
        ])->assertOk()->assertJson(['written' => 1]);

        $this->assertSame('Philippines', ShopeeListing::query()->where('product_id', $a)->first()->attribute_values['Brand Origin']);
        $this->assertSame('Japan', ShopeeListing::query()->where('product_id', $b)->first()->attribute_values['Brand Origin'],
            'an existing answer is never overwritten from the batch fix');
    }
}
