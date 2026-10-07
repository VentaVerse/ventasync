<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Support\ApiLogMode;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\ventacart\Models\VentaCartApiLog;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiLogModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'ventacart'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_the_mode_vocabulary(): void
    {
        $this->assertTrue(ApiLogMode::shouldLog('all', true));
        $this->assertTrue(ApiLogMode::shouldLog('all', false));
        $this->assertFalse(ApiLogMode::shouldLog('errors', true));
        $this->assertTrue(ApiLogMode::shouldLog('errors', false));
        $this->assertFalse(ApiLogMode::shouldLog('off', true));
        $this->assertFalse(ApiLogMode::shouldLog('off', false));

        $this->assertTrue(ApiLogMode::shouldLog(null, true));
        $this->assertTrue(ApiLogMode::shouldLog('loud', false));
    }

    public function test_errors_only_keeps_the_failure_and_drops_the_routine_call(): void
    {
        ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main', 'api_log_mode' => 'errors']);

        ShopeeApiLog::safeCreate(['method' => 'GET', 'api_path' => '/api/v2/order/get_order_list', 'ok' => true, 'response_status' => 200]);
        ShopeeApiLog::safeCreate(['method' => 'POST', 'api_path' => '/api/v2/product/add_item', 'ok' => false, 'response_status' => 400]);

        $this->assertSame(1, ShopeeApiLog::count(), 'errors only: the routine fetch stays out');
        $this->assertSame('/api/v2/product/add_item', ShopeeApiLog::first()->api_path);
    }

    public function test_off_records_nothing_and_all_records_everything(): void
    {
        $setting = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main', 'api_log_mode' => 'off']);

        ShopeeApiLog::safeCreate(['method' => 'POST', 'api_path' => '/x', 'ok' => false, 'response_status' => 500]);
        $this->assertSame(0, ShopeeApiLog::count(), 'off means off, even for failures');

        $setting->update(['api_log_mode' => 'all']);
        ShopeeApiLog::safeCreate(['method' => 'GET', 'api_path' => '/y', 'ok' => true, 'response_status' => 200]);
        ShopeeApiLog::safeCreate(['method' => 'GET', 'api_path' => '/z', 'ok' => false, 'response_status' => 400]);
        $this->assertSame(2, ShopeeApiLog::count());
    }

    public function test_the_panel_filters_and_pages_without_losing_the_tab(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main', 'api_log_mode' => 'all']);
        for ($i = 0; $i < 55; $i++) {
            ShopeeApiLog::create(['method' => 'GET', 'api_path' => '/test/quiet_routine_fetch', 'ok' => true, 'response_status' => 200]);
        }
        ShopeeApiLog::create(['method' => 'POST', 'api_path' => '/test/rare_failure', 'ok' => false, 'response_status' => 400]);

        $group = \App\Models\Admin\UserGroup::create(['name' => 'Log readers']);
        $this->artisan('permissions:sync-catalogue');
        $group->permissions()->attach(
            \App\Models\Admin\Permission::whereIn('key', ['view_shopee/settings', 'manage_shopee/settings'])->pluck('id')->all()
        );
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)
            ->get(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'logs', 'log_show' => 'errors']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('/test/rare_failure', $html);
        $this->assertStringNotContainsString('/test/quiet_routine_fetch', $html);

        $html = $this->actingAs($user)
            ->get(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'logs']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('logPage=2', $html, 'a second page exists');
        $this->assertMatchesRegularExpression('/href="[^"]*tab=logs[^"]*logPage=2|href="[^"]*logPage=2[^"]*tab=logs/', $html,
            'the pager carries ?tab=logs, so paging never leaves the panel');

        $html = $this->actingAs($user)
            ->get(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'logs', 'log_q' => 'rare_failure']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('/test/rare_failure', $html);
        $this->assertStringNotContainsString('/test/quiet_routine_fetch', $html);
    }

    public function test_a_client_writer_respects_the_mode_too(): void
    {
        $setting = VentaCartSetting::create([
            'store_name' => 'One', 'enabled' => true,
            'base_url' => 'https://one.ventacart.test', 'api_token' => 't',
            'api_log_mode' => 'errors',
        ]);

        Http::fake([
            'https://one.ventacart.test/api/v1/ping' => Http::response(['pong' => true]),
            'https://one.ventacart.test/api/v1/broken' => Http::response(['error' => 'no'], 500),
        ]);

        $client = new VentaCartClient($setting);
        $client->get('ping');
        $client->get('broken');

        $this->assertSame(1, VentaCartApiLog::count(), 'errors only on a client writer: the success stays out');
        $this->assertSame(500, (int) VentaCartApiLog::first()->status_code);
    }
}
