<?php

namespace Tests\Feature;

use App\Support\MarketplaceVerdict;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Services\Lazada\LazadaAttributes;
use Extensions\lazada\Services\Lazada\LazadaPushPayload;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\tiktok\Models\TikTokApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLogVerdictTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_verdict_reads_each_marketplaces_own_refusal_inside_a_200(): void
    {
        $this->assertTrue(MarketplaceVerdict::ok('shopee', true, ['error' => '', 'response' => ['item_id' => 1]]));
        $this->assertFalse(MarketplaceVerdict::ok('shopee', true, ['error' => 'error_param', 'message' => 'The max price of the product is over max limit.']));
        $this->assertTrue(MarketplaceVerdict::ok('lazada', true, ['code' => '0', 'data' => []]));
        $this->assertFalse(MarketplaceVerdict::ok('lazada', true, ['code' => '4108', 'type' => 'ISP', 'message' => 'E500: Create product failed']));
        $this->assertTrue(MarketplaceVerdict::ok('tiktok', true, ['code' => 0, 'message' => 'Success']));
        $this->assertFalse(MarketplaceVerdict::ok('tiktok', true, ['code' => 12052901, 'message' => 'Product not found']));
        $this->assertFalse(MarketplaceVerdict::ok('lazada', false, ['code' => '0']), 'a non-2xx is never ok');
        $this->assertTrue(MarketplaceVerdict::ok('lazada', true, 'not json'), 'a body without a verdict leaves the HTTP status standing');
        $this->assertTrue(MarketplaceVerdict::ok('woocommerce', true, ['code' => 'anything']), 'channels that do not bury refusals in a 200 keep the HTTP verdict');
    }

    public function test_the_three_api_logs_paint_a_refused_200_red(): void
    {
        LazadaApiLog::safeCreate(['pack' => 'lazada.product.create', 'method' => 'POST', 'api_path' => '/product/create', 'auth_required' => true, 'request_params' => [], 'response_status' => 200, 'ok' => true,
            'response_body' => ['code' => '4108', 'type' => 'ISP', 'message' => 'E500: Create product failed', 'detail' => [['field' => 'p-100005514', 'message' => 'C004: cannot be blank. Kindly input the information before re-uploading.', 'code' => 'CHK_BASIC_REQUIRED']]], 'user_id' => null]);
        $this->assertDatabaseHas('lazada_api_logs', ['api_path' => '/product/create', 'ok' => 0]);

        ShopeeApiLog::safeCreate(['pack' => 'shopee.products.add_item.bulk', 'method' => 'POST', 'api_path' => '/api/v2/product/add_item', 'auth_required' => true, 'request_params' => [], 'response_status' => 200, 'ok' => true,
            'response_body' => ['error' => 'error_param', 'message' => 'The max price of the product is over max limit.'], 'user_id' => null]);
        $this->assertDatabaseHas('shopee_api_logs', ['api_path' => '/api/v2/product/add_item', 'ok' => 0]);

        TikTokApiLog::safeCreate(['pack' => 'product-sync', 'method' => 'POST', 'api_path' => '/product/202309/products', 'auth_required' => true, 'request_params' => [], 'response_status' => 200, 'ok' => true,
            'response_body' => ['code' => 12052901, 'message' => 'Product not found'], 'user_id' => null]);
        $this->assertDatabaseHas('tiktok_api_logs', ['api_path' => '/product/202309/products', 'ok' => 0]);

        LazadaApiLog::safeCreate(['pack' => 'lazada.product.create', 'method' => 'POST', 'api_path' => '/product/create-ok', 'auth_required' => true, 'request_params' => [], 'response_status' => 200, 'ok' => true,
            'response_body' => ['code' => '0', 'data' => ['item_id' => 5]], 'user_id' => null]);
        $this->assertDatabaseHas('lazada_api_logs', ['api_path' => '/product/create-ok', 'ok' => 1]);
    }

    public function test_a_lazada_refusal_leads_with_its_detail_and_names_the_attribute_when_the_template_knows_it(): void
    {
        $result = ['status' => 200, 'ok' => true, 'body' => [
            'code' => '4108', 'type' => 'ISP', 'message' => 'E500: Create product failed',
            'detail' => [['field' => 'p-100005514', 'message' => 'C004: cannot be blank. Kindly input the information before re-uploading.', 'code' => 'CHK_BASIC_REQUIRED']],
        ]];
        $payload = app(LazadaPushPayload::class);

        $bare = $payload->extractLazadaError($result);
        $this->assertFalse($bare['ok']);
        $this->assertSame('4108', $bare['code']);
        $this->assertSame('p-100005514: C004: cannot be blank. Kindly input the information before re-uploading.', $bare['message']);

        $template = ['data' => ['attributes' => [
            ['id' => 100005514, 'name' => 'material', 'label' => 'Material', 'is_mandatory' => 1, 'input_type' => 'text'],
            ['id' => 7, 'name' => 'brand', 'label' => 'Brand', 'is_mandatory' => 1, 'input_type' => 'text'],
        ]]];
        $labeller = app(LazadaAttributes::class)->fieldLabeller($template);
        $this->assertSame('Material', $labeller('p-100005514'));
        $this->assertNull($labeller('p-999'));

        $line = $payload->formatLazadaResultMessage('Upload', $result, $labeller);
        $this->assertSame('Upload refused by Lazada: Material: C004: cannot be blank. Kindly input the information before re-uploading. (code 4108)', $line);
        $this->assertStringNotContainsString('HTTP 200', $line);
        $this->assertStringNotContainsString('OK', $line);

        $this->assertSame('Upload accepted by Lazada.', $payload->formatLazadaResultMessage('Upload', ['status' => 200, 'ok' => true, 'body' => ['code' => '0', 'data' => ['item_id' => 9]]]));
    }
}
