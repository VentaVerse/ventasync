<?php

namespace Tests\Feature;

use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Extensions\shopee\Services\Shopee\ShopeeItemCreate;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The #184 decomposition: four surfaces (push form, row push, index
 * bulk, group send) each carried their own copy of the image upload
 * loop and two controllers held duplicate private uploadImage helpers.
 * ShopeeItemCreate is now the one copy. These pins hold the two
 * deliberate behavior changes the merge made, and keep the dead
 * copies from growing back.
 */
class ShopeeItemCreateTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH = [
        'mode' => 'sandbox', 'partner_id' => 1, 'partner_key' => 'k',
        'access_token' => 't', 'shop_id' => 2, 'complete' => true,
    ];

    private function tempImage(): string
    {
        $path = storage_path('app/public/itemcreate-pin.png');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'png-bytes');

        return 'itemcreate-pin.png';
    }

    public function test_a_200_wearing_a_body_error_is_a_refusal_not_a_silent_skip(): void
    {
        /* Shopee answers HTTP 200 with the refusal in the body. The two
         * controller copies checked only $response->ok(), counted this a
         * success, found no image_id, and moved on without a word - the
         * operator's push then failed later with "No images were
         * uploaded successfully" and no reason. */
        Http::fake(['*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token.'], 200)]);

        $img = $this->tempImage();
        $r = app(ShopeeItemCreate::class)->uploadImages(app(ShopeeClient::class), self::AUTH, [$img]);

        $this->assertNotNull($r['error'],
            'A 200 with an error in the body must refuse the upload, not silently skip the image.');
        $this->assertStringContainsString('itemcreate-pin.png', $r['error'],
            'The refusal must name the file so the operator knows which image to fix.');
        $this->assertStringContainsString("Shopee's sign-in has lapsed.", $r['error'],
            'The refusal must speak the idiot-proof translation of the reason Shopee gave '
            . '(MarketplaceWords turns "Invalid access_token." into the sign-in headline).');

        unlink(storage_path('app/public/itemcreate-pin.png'));
    }

    public function test_every_upload_is_logged_even_from_the_batch_paths(): void
    {
        /* The bulk and group loops used to upload without writing
         * ShopeeApiLog, so a failed batch upload left no evidence. The
         * shared loop logs unconditionally. */
        Http::fake(['*' => Http::response(['response' => ['image_info' => ['image_id' => 'img-1']]], 200)]);

        $img = $this->tempImage();
        app(ShopeeItemCreate::class)->uploadImages(app(ShopeeClient::class), self::AUTH, [$img]);

        $this->assertDatabaseHas('shopee_api_logs', ['pack' => 'shopee.products.upload_image']);

        unlink(storage_path('app/public/itemcreate-pin.png'));
    }

    public function test_the_controllers_no_longer_carry_their_own_copies(): void
    {
        foreach ([
            'extensions/shopee/Controllers/ShopeeProductController.php',
            'extensions/shopee/Controllers/ShopeeProductGroupController.php',
        ] as $file) {
            $path = base_path($file);
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);

            foreach (['private function uploadImage', 'private function resolveLocalImagePath', 'private function tierImageUploader'] as $copy) {
                $this->assertStringNotContainsString($copy, $source,
                    "{$file} grew back a private '{$copy}' - the one copy lives in ShopeeItemCreate, "
                    . 'where the strict 200-with-body-error check and the unconditional upload log apply to every surface.');
            }
        }
    }
}
