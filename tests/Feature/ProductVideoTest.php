<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\ProductVideo;
use App\Models\User;
use App\Services\Media\ProductVideoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $desk = 'Catalog desk'): User
    {
        $group = UserGroup::create(['name' => $desk]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_catalog/product', 'manage_catalog/product', 'manage_catalog/product_video', 'view_catalog/product_video'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $sku = 'VID-1'): int
    {
        $pfx = (string) config('catalog.prefix');
        $id = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 1, 'price' => 100, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Pedal with a video', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    private function mp4(int $seconds, int $padBytes = 0): string
    {
        $timescale = 1000;
        $mvhd = 'mvhd' . "\0\0\0\0" . pack('N', 0) . pack('N', 0) . pack('N', $timescale) . pack('N', $seconds * $timescale);

        return "\0\0\0\x18" . 'ftypmp42' . "\0\0\0\0" . 'mp42isom' . $mvhd . str_repeat("\0", $padBytes);
    }

    public function test_the_header_says_how_long_a_video_runs(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('video/probe.mp4', $this->mp4(34));

        $ms = ProductVideoFile::durationMs(Storage::disk('public')->path('video/probe.mp4'));

        $this->assertNotNull($ms);
        $this->assertSame(34, (int) round($ms / 1000));
        $this->assertNull(ProductVideoFile::durationMs(Storage::disk('public')->path('video/missing.mp4')));
    }

    public function test_an_upload_is_kept_out_of_the_picture_library_and_says_what_it_is(): void
    {
        Storage::fake('public');
        $manager = $this->manager();
        $productId = $this->product();

        $answer = $this->actingAs($manager)->postJson(route('products.video.upload'), [
            'video' => UploadedFile::fake()->createWithContent('demo clip.mp4', $this->mp4(30)),
            'product_id' => $productId,
        ])->assertOk()->json('video');

        $this->assertStringStartsWith('video/' . $productId . '/', $answer['path'], 'a video never lands in catalog/, which is the picture library');
        $this->assertSame('demo clip.mp4', $answer['original_name']);
        $this->assertSame('30 seconds', $answer['length']);
        $this->assertArrayNotHasKey('refusals', $answer, 'no verdict is offered on a store\'s behalf');
        Storage::disk('public')->assertExists($answer['path']);
    }

    public function test_no_store_limit_is_written_into_this_application(): void
    {
        Storage::fake('public');
        $manager = $this->manager();

        $answer = $this->actingAs($manager)->postJson(route('products.video.upload'), [
            'video' => UploadedFile::fake()->createWithContent('quick.mp4', $this->mp4(6)),
            'product_id' => $this->product('VID-2'),
        ])->assertOk()->json('video');

        $this->assertSame('6 seconds', $answer['length'], 'what the file says about itself is still read');
        $this->assertArrayNotHasKey('refusals', $answer, 'no verdict is offered on a store\'s behalf');

        foreach ([
            'app/Services/Media/ProductVideoFile.php',
            'app/Integrations/Listings/ListingVideo.php',
            'resources/views/partials/channel-video-card.blade.php',
            'resources/views/catalog/products/partials/video_manager.blade.php',
        ] as $file) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertNotSame('', $src, "{$file} must exist for this guard to mean anything");
            foreach (['30 MB', '100 MB', '60 seconds', '10 to 60'] as $limit) {
                $this->assertStringNotContainsString($limit, $src,
                    "{$file} writes down a store's limit; it will drift and refuse a video the store would take");
            }
        }
    }

    public function test_the_product_keeps_the_video_it_was_saved_with_and_lets_it_go(): void
    {
        Storage::fake('public');
        $manager = $this->manager();
        $productId = $this->product('VID-3');
        $path = 'video/' . $productId . '/clip.mp4';
        Storage::disk('public')->put($path, $this->mp4(22));

        $this->actingAs($manager)->put(route('products.update', $productId), [
            'name' => 'Pedal with a video', 'sku' => 'VID-3', 'price' => 100, 'quantity' => 1, 'status' => 1,
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'video_path' => $path, 'video_name' => 'clip.mp4', 'video_ai' => '1',
        ])->assertRedirect();

        $row = ProductVideo::query()->where('product_id', $productId)->first();
        $this->assertNotNull($row, 'the product keeps its video');
        $this->assertSame($path, $row->path);
        $this->assertTrue($row->ai_generated, 'the seller\'s answer on AI is kept, because Shopee asks for it');
        $this->assertSame(22, (int) round($row->duration_ms / 1000));

        $this->actingAs($manager)->put(route('products.update', $productId), [
            'name' => 'Pedal with a video', 'sku' => 'VID-3', 'price' => 100, 'quantity' => 1, 'status' => 1,
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'video_path' => '',
        ])->assertRedirect();

        $this->assertNull(ProductVideo::query()->where('product_id', $productId)->first());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_video_staged_before_the_product_existed_settles_into_its_folder(): void
    {
        Storage::fake('public');
        $staged = 'video/_incoming/tok3n/clip.mp4';
        Storage::disk('public')->put($staged, $this->mp4(15));

        $settled = ProductVideoFile::settle($staged, 987);

        $this->assertSame('video/987/clip.mp4', $settled);
        Storage::disk('public')->assertExists($settled);
        Storage::disk('public')->assertMissing($staged);
    }

    public function test_removing_never_reaches_another_products_video(): void
    {
        Storage::fake('public');
        $manager = $this->manager();
        $mine = $this->product('VID-6');
        $theirs = $this->product('VID-7');

        $theirPath = 'video/' . $theirs . '/clip.mp4';
        Storage::disk('public')->put($theirPath, $this->mp4(20));
        $unclaimed = 'video/9999/orphan.mp4';
        Storage::disk('public')->put($unclaimed, $this->mp4(20));

        $this->actingAs($manager)->postJson(route('products.video.delete'), ['path' => $unclaimed, 'product_id' => $mine])
            ->assertOk()->assertJson(['kept' => true]);
        Storage::disk('public')->assertExists($unclaimed);

        ProductVideo::create(['product_id' => $theirs, 'path' => $theirPath, 'original_name' => 'clip.mp4', 'bytes' => 100, 'duration_ms' => 20000]);
        $this->actingAs($manager)->postJson(route('products.video.delete'), ['path' => $theirPath, 'product_id' => $mine])->assertOk();
        $this->assertNull(ProductVideo::query()->where('product_id', $theirs)->first(), 'the row goes with its file, so nothing points at a file that is gone');
        Storage::disk('public')->assertMissing($theirPath);
    }

    public function test_a_staged_video_belongs_to_whoever_uploaded_it(): void
    {
        Storage::fake('public');
        $manager = $this->manager();

        $staged = $this->actingAs($manager)->postJson(route('products.video.upload'), [
            'video' => UploadedFile::fake()->createWithContent('new.mp4', $this->mp4(20)),
            'token' => 'tok3n',
        ])->assertOk()->json('video.path');

        $this->assertStringStartsWith('video/_incoming/' . $manager->id . '-tok3n/', $staged);

        $other = $this->manager('Second catalog desk');
        $this->actingAs($other)->postJson(route('products.video.delete'), ['path' => $staged])->assertOk()->assertJson(['kept' => true]);
        Storage::disk('public')->assertExists($staged);

        $this->actingAs($manager)->postJson(route('products.video.delete'), ['path' => $staged])->assertOk();
        Storage::disk('public')->assertMissing($staged);
    }

    public function test_an_upload_for_a_product_that_does_not_exist_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager())->postJson(route('products.video.upload'), [
            'video' => UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4(20)),
            'product_id' => 987654,
        ])->assertNotFound();

        $this->assertSame([], Storage::disk('public')->allFiles('video'));
    }

    public function test_removing_asks_the_server_to_forget_the_file(): void
    {
        Storage::fake('public');
        $manager = $this->manager();
        $productId = $this->product('VID-4');
        $path = 'video/' . $productId . '/clip.mp4';
        Storage::disk('public')->put($path, $this->mp4(20));
        ProductVideo::create(['product_id' => $productId, 'path' => $path, 'original_name' => 'clip.mp4', 'bytes' => 100, 'duration_ms' => 20000]);

        $this->actingAs($manager)->postJson(route('products.video.delete'), ['path' => $path, 'product_id' => $productId])->assertOk();

        $this->assertNull(ProductVideo::query()->where('product_id', $productId)->first());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_product_form_asks_the_question_shopee_penalises_a_wrong_answer_to(): void
    {
        $manager = $this->manager();
        $productId = $this->product('VID-5');

        $page = $this->actingAs($manager)->get(route('products.edit', $productId))->assertOk();

        $page->assertSee('Video')
            ->assertSee('This video was made or helped by AI')
            ->assertSee('MP4 or MOV.');

        foreach (['Shopee', 'Lazada', 'TikTok', '30 MB', '10 to 60 seconds'] as $coupling) {
            $page->assertDontSee($coupling);
        }
    }
}
