<?php

namespace Tests\Feature;

use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Extensions\shopee\Services\Shopee\ShopeeItemCreate;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_add_item_treats_a_200_wearing_a_body_error_as_the_refusal_it_is_and_logs_it_so(): void
    {
        Http::fake(['*' => Http::response(['error' => 'error_param', 'message' => 'item_sku is duplicated.', 'response' => null], 200)]);

        $r = app(ShopeeItemCreate::class)->addItem(app(ShopeeClient::class), self::AUTH, ['item_name' => 'x'], 'shopee.products.add_item.test');

        $this->assertFalse($r['ok']);
        $this->assertNull($r['item_id']);
        $this->assertStringContainsString('item_sku is duplicated', $r['error'], 'the operator reads what Shopee said');
        $this->assertStringNotContainsString('returned no item id', $r['error']);
        $this->assertDatabaseHas('shopee_api_logs', ['pack' => 'shopee.products.add_item.test', 'ok' => 0]);
    }

    public function test_add_item_accepted_without_an_item_id_repeats_what_shopee_did_say(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'message' => '', 'warning' => 'Item pending review.', 'response' => []], 200)]);

        $r = app(ShopeeItemCreate::class)->addItem(app(ShopeeClient::class), self::AUTH, ['item_name' => 'x'], 'shopee.products.add_item.test');

        $this->assertTrue($r['ok']);
        $this->assertNull($r['item_id']);
        $this->assertStringContainsString('returned no item id', $r['error']);
        $this->assertStringContainsString('Item pending review.', $r['error']);
        $this->assertDatabaseHas('shopee_api_logs', ['pack' => 'shopee.products.add_item.test', 'ok' => 1]);
    }

    public function test_every_upload_is_logged_even_from_the_batch_paths(): void
    {
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
