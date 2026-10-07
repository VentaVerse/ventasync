<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSortSelectTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $this->artisan('permissions:sync-catalogue');
        $group = UserGroup::create(['name' => 'Sort desk']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_catalog/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $p = (string) config('catalog.prefix');
        $id = DB::table($p . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 1, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    public function test_newest_first_is_the_default(): void
    {
        $this->seedProduct('Alpha Older', 'SRT-1');
        $this->seedProduct('Zulu Newer', 'SRT-2');

        $html = $this->actingAs($this->viewer())
            ->get(route('products.index'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Alpha Older'), strpos($html, 'Zulu Newer'),
            'with nothing chosen, the newest product leads');
        $this->assertMatchesRegularExpression('/value="product_id:desc"[^>]*selected/', $html,
            'and the select says so');
    }

    public function test_the_select_carries_field_and_direction_as_one_value(): void
    {
        $this->seedProduct('Alpha Older', 'SRT-1');
        $this->seedProduct('Zulu Newer', 'SRT-2');

        $html = $this->actingAs($this->viewer())
            ->get(route('products.index', ['sort_by' => 'name:asc']))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Zulu Newer'), strpos($html, 'Alpha Older'));
        $this->assertMatchesRegularExpression('/value="name:asc"[^>]*selected/', $html);
    }

    public function test_a_bogus_sort_by_falls_back_to_the_default(): void
    {
        $this->seedProduct('Alpha Older', 'SRT-1');
        $this->seedProduct('Zulu Newer', 'SRT-2');

        $html = $this->actingAs($this->viewer())
            ->get(route('products.index', ['sort_by' => 'password:desc']))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Alpha Older'), strpos($html, 'Zulu Newer'),
            'an unknown field is ignored, not interpolated into SQL');
    }

    public function test_a_header_pair_the_select_does_not_carry_is_named_not_misstated(): void
    {
        $this->seedProduct('Alpha Older', 'SRT-1');

        $html = $this->actingAs($this->viewer())
            ->get(route('products.index', ['sort' => 'status', 'dir' => 'asc']))->assertOk()->getContent();

        $this->assertStringContainsString('Sorted by column', $html,
            'the select must not claim Newest first over a status-ordered table');
    }
}
