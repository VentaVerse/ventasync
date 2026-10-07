<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\WatermarkTemplate;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WatermarkTemplateTest extends TestCase
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
    }

    private function user(array $keys): User
    {
        $group = UserGroup::create(['name' => 'WM ' . bin2hex(random_bytes(3))]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function reader(): User
    {
        return $this->user(['view_shopee/watermark_template']);
    }

    private function manager(): User
    {
        return $this->user(['view_shopee/watermark_template', 'manage_shopee/watermark_template']);
    }

    private function url(string $action, array $params = []): string
    {
        return route('ext.shopee.watermarks.' . $action, ['store' => $this->store->id] + $params);
    }

    private function template(array $attributes = []): WatermarkTemplate
    {
        return WatermarkTemplate::create($attributes + [
            'integration' => 'shopee',
            'store_id' => $this->store->id,
            'name' => 'Mark ' . bin2hex(random_bytes(3)),
            'image_path' => $this->mark(),
        ]);
    }

    private function mark(string $name = 'logo.png'): string
    {
        Storage::fake('public');
        $im = imagecreatetruecolor(50, 50);
        imagesavealpha($im, true);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();

        Storage::disk('public')->put('catalog/marks/' . $name, $bytes);

        return 'catalog/marks/' . $name;
    }

    public function test_a_manager_creates_a_template_and_it_appears_in_the_list(): void
    {
        $path = $this->mark();

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Shop logo', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => -3, 'offset_y_percent' => -2.5, 'transparency' => 20,
        ])->assertRedirect($this->url('index'));

        $template = WatermarkTemplate::first();
        $this->assertSame('Shop logo', $template->name);
        $this->assertSame($path, $template->image_path);
        $this->assertSame('shopee', $template->integration, 'a template is created into the store whose page created it');
        $this->assertSame($this->store->id, (int) $template->store_id);
        $this->assertSame(0.8, round((float) $template->opacity, 2));
        $this->assertSame(20, $template->transparencyPercent());
        $this->assertSame(-3.0, (float) $template->offset_x_percent);
        $this->assertSame(-2.5, (float) $template->offset_y_percent);

        $this->actingAs($this->reader())->get($this->url('index'))
            ->assertOk()->assertSee('Shop logo');
    }

    public function test_a_store_never_sees_another_stores_templates(): void
    {
        $path = $this->mark();
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Second store']);

        $mine = $this->template(['name' => 'Mine', 'image_path' => $path]);
        $theirs = WatermarkTemplate::create(['integration' => 'shopee', 'store_id' => $second->id, 'name' => 'Theirs', 'image_path' => $path]);
        $elsewhere = WatermarkTemplate::create(['integration' => 'lazada', 'store_id' => $this->store->id, 'name' => 'Elsewhere', 'image_path' => $path]);

        $this->actingAs($this->reader())->get($this->url('index'))
            ->assertOk()->assertSee('Mine')->assertDontSee('Theirs')->assertDontSee('Elsewhere');

        $this->assertSame(['Mine'], WatermarkTemplate::forStore('shopee', $this->store->id)->pluck('name')->all());

        $this->actingAs($this->manager())->get($this->url('edit', ['template' => $theirs->id]))->assertNotFound();
        $this->actingAs($this->manager())->delete($this->url('destroy', ['template' => $theirs->id]))->assertNotFound();
        $this->assertSame(3, WatermarkTemplate::count());
        $this->assertNotNull($elsewhere->fresh());
        $this->assertNotNull($mine->fresh());
    }

    public function test_two_stores_may_use_the_same_name(): void
    {
        $path = $this->mark();
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Second store']);
        WatermarkTemplate::create(['integration' => 'shopee', 'store_id' => $second->id, 'name' => 'Shop logo', 'image_path' => $path]);

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Shop logo', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertRedirect($this->url('index'));

        $this->assertSame(2, WatermarkTemplate::where('name', 'Shop logo')->count());

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Shop logo', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertSessionHasErrors('name');
    }

    public function test_a_mark_that_is_not_in_the_library_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Forged', 'image_path' => '../../etc/passwd', 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertSessionHasErrors('image_path');

        $this->assertSame(0, WatermarkTemplate::count());
    }

    public function test_the_template_page_asks_only_about_the_mark(): void
    {
        $html = $this->actingAs($this->manager())->get($this->url('create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('stamp_main', $html);
        $this->assertStringNotContainsStringIgnoringCase('main image', $html);
    }

    public function test_a_template_that_would_stamp_nothing_or_leave_the_frame_is_refused(): void
    {
        $path = $this->mark();

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Invisible', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0,
            'transparency' => 100,
        ])->assertSessionHasErrors('transparency');

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Off the edge', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 80, 'offset_y_percent' => 0,
            'transparency' => 0,
        ])->assertSessionHasErrors('offset_x_percent');

        $this->assertSame(0, WatermarkTemplate::count());
    }

    public function test_an_out_of_range_preview_still_draws_a_picture(): void
    {
        $markPath = $this->mark();
        $pfx = (string) config('catalog.prefix');
        $im = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($im);
        Storage::disk('public')->put('catalog/sample.jpg', ob_get_clean());
        \DB::table($pfx . 'product')->insert([
            'model' => 'S', 'sku' => 'S', 'price' => 1, 'quantity' => 1, 'status' => 1, 'image' => 'catalog/sample.jpg',
            'date_added' => now(), 'date_modified' => now(),
        ]);

        $response = $this->actingAs($this->reader())->get($this->url('preview', [
            'image_path' => $markPath, 'position' => 'bottom-right',
            'size_percent' => 400, 'offset_x_percent' => -900, 'offset_y_percent' => 900, 'transparency' => 2,
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_stale_newest_product_does_not_blank_the_preview(): void
    {
        $markPath = $this->mark();
        $pfx = (string) config('catalog.prefix');

        $im = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($im);
        Storage::disk('public')->put('catalog/real.jpg', ob_get_clean());

        \DB::table($pfx . 'product')->insert([
            'model' => 'REAL', 'sku' => 'REAL', 'price' => 1, 'quantity' => 1, 'status' => 1,
            'image' => 'catalog/real.jpg', 'date_added' => now(), 'date_modified' => now(),
        ]);
        \DB::table($pfx . 'product')->insert([
            'model' => 'STALE', 'sku' => 'STALE', 'price' => 1, 'quantity' => 1, 'status' => 1,
            'image' => 'catalog/deleted-last-year.jpg', 'date_added' => now(), 'date_modified' => now(),
        ]);

        $response = $this->actingAs($this->reader())->get($this->url('preview', [
            'image_path' => $markPath, 'position' => 'bottom-right',
            'size_percent' => 18, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_an_empty_catalog_still_draws_a_preview(): void
    {
        $markPath = $this->mark();

        $response = $this->actingAs($this->reader())->get($this->url('preview', [
            'image_path' => $markPath, 'position' => 'bottom-right',
            'size_percent' => 18, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_listings_own_template_beats_its_groups_and_none_means_none(): void
    {
        $path = $this->mark();
        $own = $this->template(['name' => 'Own', 'image_path' => $path]);
        $group = $this->template(['name' => 'Group', 'image_path' => $path]);
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Second store']);
        $foreign = WatermarkTemplate::create(['integration' => 'shopee', 'store_id' => $second->id, 'name' => 'Foreign', 'image_path' => $path]);

        $store = $this->store->id;
        $this->assertSame($own->id, WatermarkTemplate::resolve($own->id, $group->id, 'shopee', $store)?->id);
        $this->assertSame($group->id, WatermarkTemplate::resolve(null, $group->id, 'shopee', $store)?->id);
        $this->assertNull(WatermarkTemplate::resolve(null, null, 'shopee', $store));
        $this->assertNull(WatermarkTemplate::resolve(999999, null, 'shopee', $store));
        $this->assertNull(WatermarkTemplate::resolve($foreign->id, null, 'shopee', $store));
        $this->assertNull(WatermarkTemplate::idOrNull($foreign->id, 'shopee', $store));
        $this->assertSame($own->id, WatermarkTemplate::idOrNull($own->id, 'shopee', $store));
    }

    public function test_a_reader_cannot_write_and_a_stranger_cannot_look(): void
    {
        $template = $this->template(['name' => 'Locked']);

        $refused = fn ($response) => $this->assertContains($response->status(), [302, 403],
            'A reader was allowed through to a manage surface.');

        $refused($this->actingAs($this->reader())->get($this->url('create')));
        $refused($this->actingAs($this->reader())->delete($this->url('destroy', ['template' => $template->id])));
        $this->assertSame(1, WatermarkTemplate::count(), 'A reader deleted a template.');

        $refused($this->actingAs($this->user([]))->get($this->url('index')));
        $refused($this->get($this->url('index')));
    }

    public function test_a_template_can_be_retired(): void
    {
        $template = $this->template(['name' => 'Old promo']);

        $this->actingAs($this->manager())->delete($this->url('destroy', ['template' => $template->id]))
            ->assertRedirect($this->url('index'));

        $this->assertSame(0, WatermarkTemplate::count());
        $this->assertNull(WatermarkTemplate::resolve($template->id, null, 'shopee', $this->store->id));
    }
}
