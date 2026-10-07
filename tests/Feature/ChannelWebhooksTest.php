<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\ChannelWebhookEvent;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChannelWebhooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\tiktok\TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);
        TikTokSetting::create(['mode' => 'production', 'app_key' => 'ak', 'app_secret' => 'as', 'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3)]);
    }

    private function shopeeSigned(array $payload): array
    {
        $body = json_encode($payload);
        $url = route('channels.webhooks.receive', 'shopee');
        $sig = hash_hmac('sha256', $url . '|' . $body, 'k');

        return [$body, $sig, $url];
    }

    public function test_a_signed_item_push_refreshes_the_mirror(): void
    {
        $link = ShopeeProductLink::create(['product_id' => 11, 'shopee_item_id' => 700, 'shopee_model_id' => 7001, 'sku' => 'WH-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()->subHour()]);

        [$body, $sig, $url] = $this->shopeeSigned(['code' => 5, 'shop_id' => 2, 'data' => ['item_id' => 700, 'item_status' => 'BANNED']]);

        $this->call('POST', $url, [], [], [], [
            'HTTP_AUTHORIZATION' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $event = ChannelWebhookEvent::query()->first();
        $this->assertTrue($event->signature_ok);
        $this->assertSame('item_status', $event->event_type);
        $this->assertNull($event->processed_at, 'applying is the processor\'s job, not the endpoint\'s');

        $this->artisan('webhooks:process')->assertExitCode(0);

        $this->assertSame('BANNED', $link->fresh()->live_status, 'the mirror learned it without waiting for the sweep');
        $event->refresh();
        $this->assertNotNull($event->processed_at);
        $this->assertStringContainsString('mirror -> BANNED', (string) $event->outcome);
    }

    public function test_a_push_touches_only_the_store_its_shop_id_names(): void
    {
        $two = ShopeeSetting::query()->create(['mode' => 'live', 'store_name' => 'Outlet', 'partner_id' => '1', 'partner_key' => 'k2', 'shop_id' => '9', 'access_token' => 't', 'refresh_token' => 'r']);
        $mine = ShopeeProductLink::create(['shopee_setting_id' => $two->id, 'product_id' => 21, 'shopee_item_id' => 700, 'sku' => 'WH-2', 'live_status' => 'NORMAL']);
        $other = ShopeeProductLink::create(['product_id' => 22, 'shopee_item_id' => 700, 'sku' => 'WH-3', 'live_status' => 'NORMAL']);

        $body = json_encode(['code' => 5, 'shop_id' => 9, 'data' => ['item_id' => 700, 'item_status' => 'BANNED']]);
        $url = route('channels.webhooks.receive', ['channel' => 'shopee']);
        $sig = hash_hmac('sha256', $url . '|' . $body, 'k2');

        $this->call('POST', $url, [], [], [], [
            'HTTP_AUTHORIZATION' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        $this->assertTrue(ChannelWebhookEvent::query()->latest('id')->first()->signature_ok,
            "a second store's own partner key must verify");

        $this->artisan('webhooks:process')->assertExitCode(0);

        $this->assertSame('BANNED', $mine->fresh()->live_status);
        $this->assertSame('NORMAL', $other->fresh()->live_status,
            "the other store's link keeps its status - the push named shop 9, not this one");
    }

    public function test_an_unsigned_delivery_is_kept_but_never_applied(): void
    {
        $link = ShopeeProductLink::create(['product_id' => 12, 'shopee_item_id' => 701, 'shopee_model_id' => 7002, 'sku' => 'WH-2', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $body = json_encode(['code' => 5, 'data' => ['item_id' => 701, 'item_status' => 'BANNED']]);
        $this->call('POST', route('channels.webhooks.receive', 'shopee'), [], [], [], [
            'HTTP_AUTHORIZATION' => 'not-a-real-signature', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertUnauthorized();

        $event = ChannelWebhookEvent::query()->first();
        $this->assertFalse($event->signature_ok);
        $this->assertNotNull($event, 'kept as evidence for diagnosing a scheme mismatch');

        $this->artisan('webhooks:process')->assertExitCode(0);

        $this->assertSame('NORMAL', $link->fresh()->live_status, 'an unverified delivery moves nothing');
        $this->assertNull($event->fresh()->processed_at);
    }

    public function test_an_unknown_channel_is_a_plain_404(): void
    {
        $this->call('POST', '/webhooks/lazada', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertNotFound();
        $this->assertSame(0, ChannelWebhookEvent::query()->count());
    }

    public function test_an_order_push_is_recorded_never_applied(): void
    {
        [$body, $sig, $url] = $this->shopeeSigned(['code' => 3, 'shop_id' => 2, 'data' => ['ordersn' => '2209XYZ', 'status' => 'SHIPPED']]);
        $this->call('POST', $url, [], [], [], [
            'HTTP_AUTHORIZATION' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->artisan('webhooks:process')->assertExitCode(0);

        $outcome = (string) ChannelWebhookEvent::query()->first()->outcome;
        $this->assertStringContainsString('2209XYZ', $outcome);
        $this->assertStringContainsString('the next order sync applies it', $outcome);
    }

    public function test_a_signed_tiktok_product_push_refreshes_the_mirror(): void
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'WH', 'sku' => 'WH', 'quantity' => 1, 'price' => 1, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        $listing = TikTokListing::create(['product_id' => $pid, 'tiktok_product_id' => 'tt-900', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()->subHour()]);

        $body = json_encode(['type' => 99, 'shop_id' => 'c', 'data' => ['product_id' => 'tt-900', 'status' => 'FREEZE']]);
        $sig = hash_hmac('sha256', 'ak' . $body, 'as');

        $this->call('POST', route('channels.webhooks.receive', 'tiktok'), [], [], [], [
            'HTTP_AUTHORIZATION' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        $this->artisan('webhooks:process')->assertExitCode(0);

        $this->assertSame('FREEZE', $listing->fresh()->live_status);
    }
}
