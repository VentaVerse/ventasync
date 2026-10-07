<?php

namespace Tests\Feature;

use App\Support\MarketplaceAnswer;
use Tests\TestCase;

class MarketplaceAnswerTest extends TestCase
{
    public function test_a_success_is_no_error(): void
    {
        $this->assertNull(MarketplaceAnswer::error('Shopee', ['ok' => true, 'body' => ['error' => '', 'message' => '']]));
        $this->assertNull(MarketplaceAnswer::error('Lazada', ['ok' => true, 'body' => ['code' => '0']]));
        $this->assertNull(MarketplaceAnswer::error('TikTok Shop', ['ok' => true, 'body' => ['code' => 0, 'message' => 'Success']]));
    }

    public function test_shopee_reads_message_error_code_and_failure_detail(): void
    {
        $this->assertSame('error_param.item_price', MarketplaceAnswer::error('Shopee', [
            'ok' => true, 'body' => ['error' => 'error_param.item_price', 'message' => ''],
        ]), 'an empty message must not hide the error code standing right beside it');

        $this->assertSame(
            'The item cannot be updated: model SKU GTR-1PC already exists',
            MarketplaceAnswer::error('Shopee', ['ok' => true, 'body' => [
                'error' => 'error_param', 'message' => 'The item cannot be updated.',
                'response' => ['failure_list' => [['fail_message' => 'model SKU GTR-1PC already exists']]],
            ]])
        );
    }

    public function test_lazada_names_the_message_with_its_code(): void
    {
        $this->assertSame('The category is not a leaf category (E501)', MarketplaceAnswer::error('Lazada', [
            'ok' => true, 'body' => ['code' => 'E501', 'message' => 'The category is not a leaf category'],
        ]));
    }

    public function test_lazada_leads_with_the_detail_when_a_create_fails_validation(): void
    {
        $this->assertSame(
            'p-100005514: C004: cannot be blank. Kindly input the information before re-uploading. (4108)',
            MarketplaceAnswer::error('Lazada', ['ok' => true, 'body' => [
                'code' => '4108', 'type' => 'ISP', 'message' => 'E500: Create product failed',
                'detail' => [['field' => 'p-100005514', 'message' => 'C004: cannot be blank. Kindly input the information before re-uploading.', 'code' => 'CHK_BASIC_REQUIRED']],
            ]])
        );
    }

    public function test_tiktok_reads_the_per_sku_refusal_inside_an_accepted_call(): void
    {
        $this->assertSame('inventory update partially failed: sku 123 not found', MarketplaceAnswer::error('TikTok Shop', [
            'ok' => true, 'body' => ['code' => 0, 'message' => 'inventory update partially failed',
                'data' => ['errors' => [['message' => 'sku 123 not found']]]],
        ]));
    }

    public function test_a_silent_failure_names_the_http_status_never_the_payload(): void
    {
        $answer = MarketplaceAnswer::errorText('Shopee', ['ok' => false, 'status' => 502, 'body' => ['some' => ['deep' => 'payload']]]);
        $this->assertSame('Shopee answered HTTP 502 with no error message.', $answer);
        $this->assertStringNotContainsString('payload', $answer, 'the body must never leak into the message');
    }

    public function test_plain_translates_a_known_refusal_and_keeps_an_unknown_one(): void
    {
        $this->assertSame(
            'Shopee is rate-limiting this app right now. Wait a minute and try the same action again.',
            \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => true, 'body' => ['error' => 'error_limit', 'message' => 'Request too frequent, rate limit exceeded']])
        );
        $this->assertSame(
            'A strange refusal nobody mapped yet',
            \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => true, 'body' => ['error' => 'x', 'message' => 'A strange refusal nobody mapped yet']]),
            'an unmapped reason keeps the marketplace\'s own words - a made-up translation would be worse'
        );
    }

    public function test_the_message_stays_one_flash_long(): void
    {
        $long = str_repeat('the printer is on fire ', 40);
        $this->assertLessThanOrEqual(301, mb_strlen(
            (string) MarketplaceAnswer::error('Lazada', ['ok' => false, 'body' => ['code' => 'E1', 'message' => $long]])
        ));
    }
}
