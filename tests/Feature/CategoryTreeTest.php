<?php

namespace Tests\Feature;

use App\Integrations\Listings\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private function shopee(array $rows): void
    {
        foreach ($rows as [$id, $parent, $name, $leaf]) {
            DB::table('shopee_categories')->insert([
                'category_id' => $id, 'parent_id' => $parent ?? 0, 'name' => $name, 'leaf' => $leaf, 'level' => 0,
            ]);
        }
    }

    public function test_roots_are_the_categories_with_no_parent(): void
    {
        $this->shopee([
            [100, 0, 'Hobbies', 0],
            [200, 0, 'Beauty', 1],
            [110, 100, 'Musical Instruments', 0],
        ]);

        $roots = CategoryTree::children('shopee', 0);

        $this->assertSame(['Beauty', 'Hobbies'], array_column($roots, 'name'), 'roots come back by name');
        $this->assertTrue($roots[0]['leaf'], 'a root with nothing below it is a leaf');
        $this->assertFalse($roots[1]['leaf']);
    }

    public function test_a_level_is_asked_for_by_its_parent(): void
    {
        $this->shopee([
            [100, 0, 'Hobbies', 0],
            [110, 100, 'Musical Instruments', 0],
            [111, 110, 'Guitars & Bass', 1],
            [120, 100, 'Toys', 1],
        ]);

        $this->assertSame(['Musical Instruments', 'Toys'], array_column(CategoryTree::children('shopee', 0, 100), 'name'));
        $this->assertSame(['Guitars & Bass'], array_column(CategoryTree::children('shopee', 0, 110), 'name'));
        $this->assertSame([], CategoryTree::children('shopee', 0, 111), 'a leaf opens no column');
    }

    public function test_a_path_is_walked_to_whatever_depth_the_tree_has(): void
    {
        $this->shopee([
            [1, 0, 'Sports', 0],
            [2, 1, 'Fitness', 0],
            [3, 2, 'Strength', 0],
            [4, 3, 'Free Weight', 0],
            [5, 4, 'Weight', 0],
            [6, 5, 'Body Weights', 1],
        ]);

        $path = CategoryTree::path('shopee', 0, 6);

        $this->assertSame(['Sports', 'Fitness', 'Strength', 'Free Weight', 'Weight', 'Body Weights'], array_column($path, 'name'));
        $this->assertTrue($path[5]['leaf'], 'the last step is the category itself');
    }

    public function test_depth_ignores_the_level_column(): void
    {
        $this->shopee([
            [1, 0, 'Sports', 0],
            [2, 1, 'Fitness', 0],
            [3, 2, 'Strength', 1],
        ]);
        DB::table('shopee_categories')->update(['level' => 0]);

        $this->assertCount(3, CategoryTree::path('shopee', 0, 3));
        $this->assertSame(['Sports'], array_column(CategoryTree::children('shopee', 0), 'name'),
            'only the real root is a root, whatever level says');
    }

    public function test_an_unknown_category_and_an_unknown_channel_answer_with_nothing(): void
    {
        $this->shopee([[1, 0, 'Sports', 1]]);

        $this->assertSame([], CategoryTree::path('shopee', 0, 999));
        $this->assertSame([], CategoryTree::children('nope', 0));
        $this->assertSame([], CategoryTree::path('nope', 0, 1));
        $this->assertFalse(CategoryTree::knows('nope'));
        $this->assertTrue(CategoryTree::knows('shopee'));
    }

    public function test_a_loop_does_not_hang_the_picker(): void
    {
        $this->shopee([
            [1, 2, 'One', 0],
            [2, 1, 'Two', 1],
        ]);

        $this->assertSame([], CategoryTree::path('shopee', 0, 2));
    }

    public function test_a_stores_own_categories_are_scoped_and_their_leaves_counted(): void
    {
        $first = DB::table('woocommerce_settings')->insertGetId(['store_name' => 'First store', 'base_url' => 'https://one.test']);
        $second = DB::table('woocommerce_settings')->insertGetId(['store_name' => 'Second store', 'base_url' => 'https://two.test']);

        foreach ([
            [$first, 10, 0, 'Pedals'],
            [$first, 11, 10, 'Overdrive'],
            [$second, 20, 0, 'Another store only'],
        ] as [$store, $id, $parent, $name]) {
            DB::table('woocommerce_categories')->insert([
                'woocommerce_setting_id' => $store, 'woo_category_id' => $id,
                'parent_id' => $parent, 'name' => $name, 'slug' => strtolower($name),
            ]);
        }

        $roots = CategoryTree::children('woocommerce', $first);

        $this->assertSame(['Pedals'], array_column($roots, 'name'), 'one store never sees another store\'s categories');
        $this->assertFalse($roots[0]['leaf'], 'something sits under it, so it is not a leaf');
        $this->assertSame([['id' => 11, 'name' => 'Overdrive', 'leaf' => true]], CategoryTree::children('woocommerce', $first, 10));
        $this->assertSame(['Another store only'], array_column(CategoryTree::children('woocommerce', $second), 'name'));
    }

    public function test_a_null_parent_is_a_root_too(): void
    {
        DB::table('tiktok_categories')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'Electronics', 'is_leaf' => 0],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Headphones', 'is_leaf' => 1],
        ]);

        $this->assertSame(['Electronics'], array_column(CategoryTree::children('tiktok', 0), 'name'));
        $this->assertSame(['Headphones'], array_column(CategoryTree::children('tiktok', 0, 1), 'name'));
        $this->assertSame(['Electronics', 'Headphones'], array_column(CategoryTree::path('tiktok', 0, 2), 'name'));
    }
}
