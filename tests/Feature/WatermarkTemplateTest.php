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

/**
 * WATERMARK TEMPLATES BELONG TO A STORE.
 *
 * The same object as a description template, kept the same way. A mark
 * carries a shop's words as much as its logo, COD accepted or an
 * authorised distributor line, so it belongs to the store that says them
 * and never travels to another.
 *
 * The mark itself is picked from the shared image library rather than
 * uploaded into the template, so one logo serves every template that points
 * at it, in whichever store.
 */
class WatermarkTemplateTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        /* The suite does not install the extensions, and these pages belong
           to one. Install Shopee, register it, and let the permission
           catalogue read its routes. */
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

    /** A route of this store's watermark pages. */
    private function url(string $action, array $params = []): string
    {
        return route('ext.shopee.watermarks.' . $action, ['store' => $this->store->id] + $params);
    }

    /** A template this store holds. */
    private function template(array $attributes = []): WatermarkTemplate
    {
        return WatermarkTemplate::create($attributes + [
            'integration' => 'shopee',
            'store_id' => $this->store->id,
            'name' => 'Mark ' . bin2hex(random_bytes(3)),
            'image_path' => $this->mark(),
        ]);
    }

    /** A real PNG in the library, since the path is checked against the disk. */
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
        // Shown as transparency, stored as the opacity the stamper multiplies by.
        $this->assertSame(0.8, round((float) $template->opacity, 2));
        $this->assertSame(20, $template->transparencyPercent());
        $this->assertSame(-3.0, (float) $template->offset_x_percent);
        $this->assertSame(-2.5, (float) $template->offset_y_percent);

        $this->actingAs($this->reader())->get($this->url('index'))
            ->assertOk()->assertSee('Shop logo');
    }

    /**
     * A store sees its own marks and nobody else's: not another Shopee
     * store's, and not another channel's. This is the rule the whole shape
     * exists for.
     */
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

        // Another store's template cannot be opened, edited or deleted from here.
        $this->actingAs($this->manager())->get($this->url('edit', ['template' => $theirs->id]))->assertNotFound();
        $this->actingAs($this->manager())->delete($this->url('destroy', ['template' => $theirs->id]))->assertNotFound();
        $this->assertSame(3, WatermarkTemplate::count());
        $this->assertNotNull($elsewhere->fresh());
        $this->assertNotNull($mine->fresh());
    }

    /** Two stores may each keep a mark of the same name, because neither sees the other's. */
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

        // Within one store the name is still its own.
        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Shop logo', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertSessionHasErrors('name');
    }

    /**
     * The mark must be a file the library actually holds: this path is read
     * at push time, on every picture of every product that uses it, and a
     * hidden input is client input like any other.
     */
    public function test_a_mark_that_is_not_in_the_library_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager())->post($this->url('store'), [
            'name' => 'Forged', 'image_path' => '../../etc/passwd', 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertSessionHasErrors('image_path');

        $this->assertSame(0, WatermarkTemplate::count());
    }

    /** The template holds only the mark: which photos get it is the listing's choice. */
    public function test_the_template_page_asks_only_about_the_mark(): void
    {
        $html = $this->actingAs($this->manager())->get($this->url('create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('stamp_main', $html);
        $this->assertStringNotContainsStringIgnoringCase('main image', $html);
    }

    /**
     * The sliders cannot hold a value the stamper refuses, but a form post
     * can, and the save still says no: an offset past half the edge, or a
     * transparency that makes the mark vanish, is a mistake to name rather
     * than a template to keep.
     */
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

    /**
     * THE PREVIEW CLAMPS, IT NEVER REFUSES. An out-of-range number answered
     * the preview with a validation error the picture could not show, so the
     * sample went blank. A preview is asked while somebody is still
     * deciding; it draws the nearest true thing instead.
     */
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

    /**
     * The sample is a real photograph from the library, and one stale row
     * does not take the preview down with it. A product's image column can
     * name a file that is no longer on disk; the preview used to try the
     * newest product alone and answer 404 when that one was stale, which
     * read as "the watermark is broken".
     */
    public function test_a_stale_newest_product_does_not_blank_the_preview(): void
    {
        $markPath = $this->mark();
        $pfx = (string) config('catalog.prefix');

        $im = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($im);
        Storage::disk('public')->put('catalog/real.jpg', ob_get_clean());

        // An older product whose file is really there.
        \DB::table($pfx . 'product')->insert([
            'model' => 'REAL', 'sku' => 'REAL', 'price' => 1, 'quantity' => 1, 'status' => 1,
            'image' => 'catalog/real.jpg', 'date_added' => now(), 'date_modified' => now(),
        ]);
        // A newer one pointing at a file that is gone.
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

    /**
     * A catalog with no photograph at all still draws something. A fresh
     * install has no products, and a preview that answers 404 there teaches
     * the seller the mark is broken when it is only untried.
     */
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

    /**
     * A listing's own template wins over its group's - the order every
     * other setting on a listing keeps - and a template belonging to
     * another store is the same as naming none.
     */
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
        // A template that was deleted is the same as naming none.
        $this->assertNull(WatermarkTemplate::resolve(999999, null, 'shopee', $store));
        // So is one another store holds: a listing never wears a mark its store does not have.
        $this->assertNull(WatermarkTemplate::resolve($foreign->id, null, 'shopee', $store));
        $this->assertNull(WatermarkTemplate::idOrNull($foreign->id, 'shopee', $store));
        $this->assertSame($own->id, WatermarkTemplate::idOrNull($own->id, 'shopee', $store));
    }

    public function test_a_reader_cannot_write_and_a_stranger_cannot_look(): void
    {
        $template = $this->template(['name' => 'Locked']);

        /* Refused is refused: this app answers a missing permission with a
           403 on some verbs and a redirect away on others, and which one
           is not what this test is about. What matters is that the reader
           does not arrive and the row survives. */
        $refused = fn ($response) => $this->assertContains($response->status(), [302, 403],
            'A reader was allowed through to a manage surface.');

        $refused($this->actingAs($this->reader())->get($this->url('create')));
        $refused($this->actingAs($this->reader())->delete($this->url('destroy', ['template' => $template->id])));
        $this->assertSame(1, WatermarkTemplate::count(), 'A reader deleted a template.');

        $refused($this->actingAs($this->user([]))->get($this->url('index')));
        $refused($this->get($this->url('index')));
    }

    /**
     * Deleting a template never repairs what pointed at it, and does not
     * need to: a listing naming a template that no longer exists stamps
     * nothing, exactly as if it named none. Refusing the delete instead
     * would make a template impossible to retire.
     */
    public function test_a_template_can_be_retired(): void
    {
        $template = $this->template(['name' => 'Old promo']);

        $this->actingAs($this->manager())->delete($this->url('destroy', ['template' => $template->id]))
            ->assertRedirect($this->url('index'));

        $this->assertSame(0, WatermarkTemplate::count());
        $this->assertNull(WatermarkTemplate::resolve($template->id, null, 'shopee', $this->store->id));
    }
}
