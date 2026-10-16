<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackToTheSearchTest extends TestCase
{
    use RefreshDatabase;

    private function catalogManager(): User
    {
        $this->artisan('permissions:sync-catalog');
        $group = UserGroup::create(['name' => 'Back-to desk']);
        $group->permissions()->attach(Permission::query()
            ->where('key', 'like', '%catalog%')->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku, string $name): int
    {
        $productId = DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_the_edit_page_carries_the_search_it_came_from(): void
    {
        $pid = $this->seedProduct('BTS-1', 'Search me');
        $user = $this->catalogManager();
        $searchUrl = route('products.index') . '?q=Search+me&page=2';

        $page = $this->actingAs($user)
            ->from($searchUrl)
            ->get(route('products.edit', $pid))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="_return" value="/catalog/products?q=Search+me', $page,
            'the hidden return must be a LOCAL path - absolute URLs fail the safety check and lose the search');
    }

    public function test_saving_landss_back_on_that_search(): void
    {
        $pid = $this->seedProduct('BTS-2', 'Search me too');
        $user = $this->catalogManager();

        $r = $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Search me too', 'sku' => 'BTS-2',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '1', 'length' => '10', 'width' => '10', 'height' => '10',
            'brand_id' => '0', 'images_json' => '["catalog/demo.jpg"]',
            'description' => 'A dependable test product description that comfortably clears the eighty character floor the marketplaces set.',
            '_return' => '/catalog/products?q=Search+me+too&page=2',
        ]);

        $r->assertRedirect('/catalog/products?q=Search+me+too&page=2');
    }

    public function test_a_hostile_return_never_reaches_the_href(): void
    {
        $pid = $this->seedProduct('BTS-XSS', 'Hostile return');
        $user = $this->catalogManager();
        $editUrl = route('products.edit', $pid);

        $this->actingAs($user)->from($editUrl)->put(route('products.update', $pid), [
            'name' => '',
            '_return' => 'javascript:alert(1)',
        ]);

        $page = $this->actingAs($user)->get($editUrl)->assertOk()->getContent();
        $this->assertStringNotContainsString('href="javascript:', $page);
        $this->assertStringNotContainsString('value="javascript:', $page);
    }

    public function test_an_outside_url_is_never_followed(): void
    {
        $pid = $this->seedProduct('BTS-3', 'Do not follow');
        $user = $this->catalogManager();

        $r = $this->actingAs($user)->put(route('products.update', $pid), [
            'name' => 'Do not follow', 'sku' => 'BTS-3',
            'price' => '300', 'quantity' => '5', 'status' => '1',
            'weight' => '1', 'length' => '10', 'width' => '10', 'height' => '10',
            'brand_id' => '0', 'images_json' => '["catalog/demo.jpg"]',
            'description' => 'A dependable test product description that comfortably clears the eighty character floor the marketplaces set.',
            '_return' => 'https://evil.example/phish',
        ]);

        $r->assertRedirect(route('products.index'));
    }

    public function test_categories_and_brands_get_the_same_courtesy(): void
    {
        $user = $this->catalogManager();

        $catId = DB::table('categories')->insertGetId([
            'parent_id' => 0, 'sort_order' => 0, 'status' => 1,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('category_descriptions')->insert([
            'category_id' => $catId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Strings', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'seo_keyword' => '', 'seo_h1' => '', 'seo_h2' => '', 'seo_h3' => '',
        ]);

        $this->actingAs($user)->put(route('categories.update', $catId), [
            'name' => 'Strings', 'status' => '1', 'parent_id' => '0',
            '_return' => '/catalog/categories?q=Str',
        ])->assertRedirect('/catalog/categories?q=Str');

        $manId = DB::table('brands')->insertGetId(['name' => 'Gotoh', 'sort_order' => 0]);
        $this->actingAs($user)->put(route('brands.update', $manId), [
            'name' => 'Gotoh',
            '_return' => '/catalog/brands?q=Go',
        ])->assertRedirect('/catalog/brands?q=Go');
    }
}
