<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WATERMARK TEMPLATES IN THE CATALOG (VENT2-128).
 *
 * the owner, 2026-09-10: "This is part of Catalog. so inside catalog. Make it
 * a template CRUD. Then for every listing, and every group, they can
 * choose the template." And the mark itself is picked from the image
 * library rather than uploaded into the template, so one logo serves every
 * template that points at it.
 */
class WatermarkTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $keys): User
    {
        $group = UserGroup::create(['name' => 'WM ' . bin2hex(random_bytes(3))]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function reader(): User
    {
        return $this->user(['view_catalog/watermark_template']);
    }

    private function manager(): User
    {
        return $this->user(['view_catalog/watermark_template', 'manage_catalog/watermark_template']);
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
        imagedestroy($im);

        Storage::disk('public')->put('catalog/marks/' . $name, $bytes);

        return 'catalog/marks/' . $name;
    }

    public function test_a_manager_creates_a_template_and_it_appears_in_the_list(): void
    {
        $path = $this->mark();

        $this->actingAs($this->manager())->post(route('watermarks.store'), [
            'name' => 'Shop logo', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => -3, 'offset_y_percent' => -2.5, 'transparency' => 20,
        ])->assertRedirect(route('watermarks.index'));

        $template = WatermarkTemplate::first();
        $this->assertSame('Shop logo', $template->name);
        $this->assertSame($path, $template->image_path);
        // Shown as transparency, stored as the opacity the stamper multiplies by.
        $this->assertSame(0.8, round((float) $template->opacity, 2));
        $this->assertSame(20, $template->transparencyPercent());
        $this->assertSame(-3.0, (float) $template->offset_x_percent);
        $this->assertSame(-2.5, (float) $template->offset_y_percent);

        $this->actingAs($this->reader())->get(route('watermarks.index'))
            ->assertOk()->assertSee('Shop logo');
    }

    /**
     * The mark must be a file the library actually holds: this path is read
     * at push time, on every picture of every product that uses it, and a
     * hidden input is client input like any other.
     */
    public function test_a_mark_that_is_not_in_the_library_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager())->post(route('watermarks.store'), [
            'name' => 'Forged', 'image_path' => '../../etc/passwd', 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'transparency' => 0,
        ])->assertSessionHasErrors('image_path');

        $this->assertSame(0, WatermarkTemplate::count());
    }

    /** The template holds only the mark: which photos get it, and any marketplace's rule, are not asked here. */
    public function test_the_template_page_asks_only_about_the_mark(): void
    {
        $html = $this->actingAs($this->manager())->get(route('watermarks.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('stamp_main', $html);
        $this->assertStringNotContainsStringIgnoringCase('main image', $html);
        foreach (['Shopee', 'Lazada', 'TikTok'] as $channel) {
            $this->assertStringNotContainsString($channel, $html, "a catalog page names no marketplace ({$channel})");
        }
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

        $this->actingAs($this->manager())->post(route('watermarks.store'), [
            'name' => 'Invisible', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 0, 'offset_y_percent' => 0,
            'transparency' => 100,
        ])->assertSessionHasErrors('transparency');

        $this->actingAs($this->manager())->post(route('watermarks.store'), [
            'name' => 'Off the edge', 'image_path' => $path, 'position' => 'bottom-right',
            'size_percent' => 12, 'offset_x_percent' => 80, 'offset_y_percent' => 0,
            'transparency' => 0,
        ])->assertSessionHasErrors('offset_x_percent');

        $this->assertSame(0, WatermarkTemplate::count());
    }

    /**
     * THE PREVIEW CLAMPS, IT NEVER REFUSES. Typing 2 into the old strength
     * box answered the preview with a validation error the picture could
     * not show, so the sample went blank (the owner, 2026-09-11). A preview is
     * asked while somebody is still deciding; it draws the nearest true
     * thing instead.
     */
    public function test_an_out_of_range_preview_still_draws_a_picture(): void
    {
        $markPath = $this->mark();
        $pfx = (string) config('catalog.prefix');
        $im = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($im);
        Storage::disk('public')->put('catalog/sample.jpg', ob_get_clean());
        imagedestroy($im);
        \DB::table($pfx . 'product')->insert([
            'model' => 'S', 'sku' => 'S', 'price' => 1, 'quantity' => 1, 'status' => 1, 'image' => 'catalog/sample.jpg',
            'date_added' => now(), 'date_modified' => now(),
        ]);

        $response = $this->actingAs($this->reader())->get(route('watermarks.preview', [
            'image_path' => $markPath, 'position' => 'bottom-right',
            'size_percent' => 400, 'offset_x_percent' => -900, 'offset_y_percent' => 900, 'transparency' => 2,
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    /**
     * A listing's own template wins over its group's - the order every
     * other setting on a listing already keeps.
     */
    public function test_a_listings_own_template_beats_its_groups_and_none_means_none(): void
    {
        $path = $this->mark();
        $own = WatermarkTemplate::create(['name' => 'Own', 'image_path' => $path]);
        $group = WatermarkTemplate::create(['name' => 'Group', 'image_path' => $path]);

        $this->assertSame($own->id, WatermarkTemplate::resolve($own->id, $group->id)?->id);
        $this->assertSame($group->id, WatermarkTemplate::resolve(null, $group->id)?->id);
        $this->assertNull(WatermarkTemplate::resolve(null, null));
        // A template that was deleted is the same as naming none.
        $this->assertNull(WatermarkTemplate::resolve(999999, null));
    }

    public function test_a_reader_cannot_write_and_a_stranger_cannot_look(): void
    {
        $path = $this->mark();
        $template = WatermarkTemplate::create(['name' => 'Locked', 'image_path' => $path]);

        /* Refused is refused: this app answers a missing permission with a
           403 on some verbs and a redirect away on others, and which one
           is not what this test is about. What matters is that the reader
           does not arrive and the row survives. */
        $refused = fn ($response) => $this->assertContains($response->status(), [302, 403],
            'A reader was allowed through to a manage surface.');

        $refused($this->actingAs($this->reader())->get(route('watermarks.create')));
        $refused($this->actingAs($this->reader())->delete(route('watermarks.destroy', $template->id)));
        $this->assertSame(1, WatermarkTemplate::count(), 'A reader deleted a template.');

        $refused($this->actingAs($this->user([]))->get(route('watermarks.index')));
        $refused($this->get(route('watermarks.index')));
    }

    /**
     * Deleting a template never repairs what pointed at it, and does not
     * need to: a listing naming a template that no longer exists stamps
     * nothing, exactly as if it named none. Refusing the delete instead
     * would make a template impossible to retire.
     */
    public function test_a_template_can_be_retired(): void
    {
        $template = WatermarkTemplate::create(['name' => 'Old promo', 'image_path' => $this->mark()]);

        $this->actingAs($this->manager())->delete(route('watermarks.destroy', $template->id))
            ->assertRedirect(route('watermarks.index'));

        $this->assertSame(0, WatermarkTemplate::count());
        $this->assertNull(WatermarkTemplate::resolve($template->id, null));
    }
}
