<?php

namespace Tests\Feature;

use App\Models\WatermarkTemplate;
use App\Services\Media\ListingWatermark;
use App\Services\Media\StampedImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which template marks a listing's pictures: its own, else its product
 * group's in the same store, and on which pictures.
 */
class ListingWatermarkTest extends TestCase
{
    use RefreshDatabase;

    private function png(string $path, bool $alpha = false): string
    {
        $img = imagecreatetruecolor($alpha ? 40 : 200, $alpha ? 40 : 200);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, $alpha ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, 20, 90, 160));
        if ($alpha) {
            imagefilledrectangle($img, 0, 0, 20, 20, imagecolorallocatealpha($img, 255, 255, 255, 0));
        }
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function template(string $name): WatermarkTemplate
    {
        return WatermarkTemplate::create([
            'integration' => 'shopee', 'store_id' => 1,
            'name' => $name, 'image_path' => $this->png('catalog/marks/' . $name . '.png', true),
            'position' => 'bottom-right', 'size_percent' => 20, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'opacity' => 1,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_the_listings_own_template_wins_over_its_groups(): void
    {
        $own = $this->template('own');
        $groups = $this->template('group');
        $pictures = [$this->png('catalog/p/a.png'), $this->png('catalog/p/b.png')];

        $mine = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => $own->id, 'watermark_all_images' => false], $groups->id, 'shopee', 1);
        $lent = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => null, 'watermark_all_images' => false], $groups->id, 'shopee', 1);

        $this->assertStringStartsWith(StampedImages::DIR . '/', $mine[0]);
        $this->assertNotSame($mine[0], $lent[0], 'a listing with its own template is not marked with its group\'s');
        $this->assertSame('catalog/p/b.png', $mine[1], 'unticked: the main picture only');
    }

    public function test_the_tick_marks_every_picture_and_no_template_marks_none(): void
    {
        $own = $this->template('own');
        $pictures = [$this->png('catalog/p/a.png'), $this->png('catalog/p/b.png')];

        $all = ListingWatermark::paths($pictures, (object) ['watermark_template_id' => $own->id, 'watermark_all_images' => true], null, 'shopee', 1);
        $this->assertStringStartsWith(StampedImages::DIR . '/', $all[1]);

        $this->assertSame($pictures, ListingWatermark::paths($pictures, null, null, 'shopee', 1));
    }

    public function test_the_group_template_is_read_from_the_products_group_in_that_store(): void
    {
        $a = $this->template('store-a');
        $b = $this->template('store-b');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $groupA = DB::table('venta_product_groups')->insertGetId(['venta_setting_id' => 1, 'name' => 'A', 'watermark_template_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
        $groupB = DB::table('venta_product_groups')->insertGetId(['venta_setting_id' => 2, 'name' => 'B', 'watermark_template_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('venta_product_group_products')->insert([
            ['venta_product_group_id' => $groupA, 'product_id' => 77, 'created_at' => now(), 'updated_at' => now()],
            ['venta_product_group_id' => $groupB, 'product_id' => 77, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $args = ['venta_product_groups', 'venta_product_group_products', 'venta_product_group_id', 77, 'venta_setting_id'];
        $this->assertSame($a->id, ListingWatermark::groupTemplateId(...[...$args, 1]));
        $this->assertSame($b->id, ListingWatermark::groupTemplateId(...[...$args, 2]));
        $this->assertNull(ListingWatermark::groupTemplateId('venta_product_groups', 'venta_product_group_products', 'venta_product_group_id', 999, 'venta_setting_id', 1));
    }
}
