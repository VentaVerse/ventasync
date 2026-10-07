<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryPickerTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        $this->store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        foreach ([
            [100, 0, 'Hobbies & Collections', 0],
            [110, 100, 'Musical Instruments', 0],
            [111, 110, 'Guitars & Bass', 1],
            [112, 110, 'Keyboards', 1],
            [200, 0, 'Beauty', 1],
        ] as [$id, $parent, $name, $leaf]) {
            DB::table('shopee_categories')->insert([
                'category_id' => $id, 'parent_id' => $parent, 'name' => $name, 'leaf' => $leaf, 'level' => 0,
            ]);
        }
    }

    private function reader(): User
    {
        $group = UserGroup::create(['name' => 'CT ' . bin2hex(random_bytes(3))]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function url(string $action, array $params = []): string
    {
        return route('ext.shopee.products.' . $action, ['store' => $this->store->id] + $params);
    }

    public function test_the_roots_come_back_when_no_parent_is_named(): void
    {
        $body = $this->actingAs($this->reader())->getJson($this->url('category_children'))->assertOk()->json();

        $this->assertNull($body['parent']);
        $this->assertSame(['Beauty', 'Hobbies & Collections'], array_column($body['items'], 'name'));
        $this->assertTrue($body['items'][0]['leaf'], 'a root with nothing under it opens no column');
        $this->assertFalse($body['items'][1]['leaf']);
    }

    public function test_one_level_comes_back_for_a_named_parent(): void
    {
        $body = $this->actingAs($this->reader())->getJson($this->url('category_children', ['parent' => 110]))->assertOk()->json();

        $this->assertSame(110, $body['parent']);
        $this->assertSame(['Guitars & Bass', 'Keyboards'], array_column($body['items'], 'name'));
        $this->assertTrue($body['items'][0]['leaf']);
    }

    public function test_a_path_carries_every_column_along_its_way(): void
    {
        $body = $this->actingAs($this->reader())->getJson($this->url('category_path', ['id' => 111]))->assertOk()->json();

        $this->assertSame(['Hobbies & Collections', 'Musical Instruments', 'Guitars & Bass'], array_column($body['path'], 'name'));

        $this->assertCount(3, $body['levels']);
        $this->assertNull($body['levels'][0]['parent']);
        $this->assertSame(['Beauty', 'Hobbies & Collections'], array_column($body['levels'][0]['items'], 'name'));
        $this->assertSame(100, $body['levels'][1]['parent']);
        $this->assertSame(['Musical Instruments'], array_column($body['levels'][1]['items'], 'name'));
        $this->assertSame(110, $body['levels'][2]['parent']);
        $this->assertSame(['Guitars & Bass', 'Keyboards'], array_column($body['levels'][2]['items'], 'name'),
            'the last column holds the chosen leaf beside its siblings');
    }

    public function test_a_category_the_store_does_not_hold_is_nothing_chosen(): void
    {
        $body = $this->actingAs($this->reader())->getJson($this->url('category_path', ['id' => 999999]))->assertOk()->json();

        $this->assertSame([], $body['path']);
        $this->assertCount(1, $body['levels'], 'the roots still come back, so the picker opens rather than breaking');
    }

    public function test_a_stranger_is_refused(): void
    {
        $refused = fn ($r) => $this->assertContains($r->status(), [302, 401, 403]);

        $refused($this->getJson($this->url('category_children')));
        $refused($this->actingAs(User::factory()->create(['user_group_id' => UserGroup::create(['name' => 'None ' . bin2hex(random_bytes(3))])->id]))
            ->getJson($this->url('category_children')));
    }

    public function test_a_bad_parent_is_refused_rather_than_guessed(): void
    {
        $this->actingAs($this->reader())->getJson($this->url('category_children', ['parent' => 'drop table']))
            ->assertStatus(422);
        $this->actingAs($this->reader())->getJson($this->url('category_path', ['id' => 0]))
            ->assertStatus(422);
    }
}
