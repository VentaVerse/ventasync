<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\CategoryDescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryEditTouchesOneRowTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name): int
    {
        $id = DB::table('categories')->insertGetId([
            'parent_id' => 0, 'sort_order' => 0, 'status' => 1,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('category_descriptions')->insert([
            'category_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => $name, 'meta_description' => '', 'meta_keyword' => '', 'seo_keyword' => '', 'seo_h1' => '', 'seo_h2' => '', 'seo_h3' => '',
        ]);

        return $id;
    }

    public function test_editing_one_category_leaves_the_others_alone(): void
    {
        $this->artisan('permissions:sync-catalog');
        $group = UserGroup::create(['name' => 'Category desk']);
        $group->permissions()->attach(Permission::query()->where('key', 'like', '%catalog%')->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $strings = $this->category('Strings');
        $pedals = $this->category('Pedals');

        $this->actingAs($user)->put(route('categories.update', $strings), [
            'name' => 'Guitar strings', 'status' => '1', 'parent_id' => '0',
        ])->assertRedirect();

        $names = DB::table('category_descriptions')->pluck('name', 'category_id');
        $this->assertSame('Guitar strings', $names[$strings]);
        $this->assertSame('Pedals', $names[$pedals]);
    }

    public function test_a_keyless_row_saves_only_itself(): void
    {
        $a = $this->category('Strings');
        $b = $this->category('Pedals');

        $row = CategoryDescription::query()->where('category_id', $a)->firstOrFail();
        $row->name = 'Picks';
        $row->save();
        $this->assertSame('Pedals', DB::table('category_descriptions')->where('category_id', $b)->value('name'));
        $this->assertSame('Picks', DB::table('category_descriptions')->where('category_id', $a)->value('name'));
    }
}
