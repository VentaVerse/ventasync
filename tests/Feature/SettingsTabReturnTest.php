<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTabReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(string $channel): User
    {
        $group = UserGroup::create(['name' => ucfirst($channel) . ' settings manager ' . uniqid()]);
        $group->permissions()->attach(
            Permission::where('key', 'like', '%_' . $channel . '/%')->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function tiktokStore(string $name): TikTokSetting
    {
        return TikTokSetting::create([
            'mode' => 'production', 'store_name' => $name, 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c-' . uniqid(), 'expires_at' => now()->addDays(3),
        ]);
    }

    public function test_shopee_log_controls_land_on_the_logs_tab_of_the_store_they_were_pressed_on(): void
    {
        $a = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main', 'api_log_mode' => 'all']);
        $b = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet', 'api_log_mode' => 'all']);
        $user = $this->manager('shopee');
        $logsTab = route('ext.shopee.settings.show', ['store' => $b->id, 'tab' => 'logs']);

        $this->actingAs($user)
            ->post('/channels/shopee/' . $b->id . '/api-log-mode', ['mode' => 'errors'])
            ->assertRedirect($logsTab);
        $this->assertSame('errors', $b->fresh()->api_log_mode);
        $this->assertSame('all', $a->fresh()->api_log_mode, 'the first store is not the one the operator was standing in');

        ShopeeApiLog::create(['shopee_setting_id' => $a->id, 'method' => 'GET', 'api_path' => '/a1', 'ok' => true, 'response_status' => 200]);
        ShopeeApiLog::create(['shopee_setting_id' => $a->id, 'method' => 'GET', 'api_path' => '/a2', 'ok' => true, 'response_status' => 200]);
        ShopeeApiLog::create(['shopee_setting_id' => $b->id, 'method' => 'GET', 'api_path' => '/b1', 'ok' => true, 'response_status' => 200]);
        $this->actingAs($user)
            ->delete('/channels/shopee/' . $b->id . '/api-logs')
            ->assertRedirect($logsTab);
        $this->assertSame(0, ShopeeApiLog::withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $b->id)->count());
        $this->assertSame(2, ShopeeApiLog::withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $a->id)->count(), 'a purge on one store never wipes another');

        $this->actingAs($user)
            ->post('/channels/shopee/' . $b->id . '/purge-raw', ['days' => 30])
            ->assertRedirect($logsTab);
    }

    public function test_shopee_status_map_and_explorer_land_on_their_own_tabs(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);
        $user = $this->manager('shopee');

        $this->actingAs($user)
            ->post('/channels/shopee/' . $store->id . '/order-status-map', ['map' => 'not-an-array'])
            ->assertRedirect(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'status']));

        $r = $this->actingAs($user)
            ->post('/channels/shopee/' . $store->id . '/explorer/run', ['method' => 'GET', 'api_path' => '/api/v2/shop/get_shop_info', 'params_json' => '{}']);
        $r->assertRedirect(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'explorer']));
        $r->assertSessionHas('shopee_result');
        $r->assertSessionHas('settings_tab', 'explorer');

        $html = $this->actingAs($user)
            ->withSession(['settings_tab' => 'explorer', 'shopee_result' => ['ok' => false, 'title' => 'Explorer says', 'data' => ['message' => 'no credentials']]])
            ->get(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'explorer']))
            ->assertOk()->getContent();
        $explorerAt = strpos($html, 'id="sp-tab-explorer"');
        $resultAt = strpos($html, 'Explorer says');
        $this->assertNotFalse($explorerAt);
        $this->assertNotFalse($resultAt, 'the explorer result is drawn');
        $this->assertGreaterThan($explorerAt, $resultAt, 'the result sits inside the Explorer panel, after its opening tag');
        $this->assertSame(1, substr_count($html, 'Last result'), 'and only there');
    }

    public function test_lazada_actions_land_on_their_tabs_and_the_store_has_a_name_and_a_switch(): void
    {
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $user = $this->manager('lazada');
        $base = '/channels/lazada/' . $store->id;

        $this->actingAs($user)->post($base . '/api-log-mode', ['mode' => 'off'])
            ->assertRedirect(route('ext.lazada.index', ['store' => $store->id, 'tab' => 'logs']));
        $this->actingAs($user)->delete($base . '/api-logs')
            ->assertRedirect(route('ext.lazada.index', ['store' => $store->id, 'tab' => 'logs']));
        $this->actingAs($user)->post($base . '/order-status-map', ['map' => 'nope'])
            ->assertRedirect(route('ext.lazada.index', ['store' => $store->id, 'tab' => 'status']));
        $this->actingAs($user)->post($base . '/explorer/run', ['method' => 'GET', 'api_path' => '/seller/get', 'params_json' => '{}'])
            ->assertRedirect(route('ext.lazada.index', ['store' => $store->id, 'tab' => 'explorer']))
            ->assertSessionHas('lazada_result');

        $html = $this->actingAs($user)->get(route('ext.lazada.index', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Store name', $html);
        $this->assertStringContainsString('cs-store-switch', $html, 'the Active switch is the system switch');

        $this->actingAs($user)->post($base . '/save', ['env' => 'live', 'region' => 'ph', 'store_name' => 'Gearshipper PH', 'enabled' => 0])->assertRedirect();
        $store->refresh();
        $this->assertSame('Gearshipper PH', $store->store_name);
        $this->assertFalse((bool) $store->enabled);
    }

    public function test_tiktok_actions_land_on_their_tabs_and_the_store_has_a_name_and_a_switch(): void
    {
        $store = $this->tiktokStore('TikTok Main');
        $user = $this->manager('tiktok');
        $base = '/channels/tiktok/' . $store->id;

        $this->actingAs($user)->post($base . '/api-log-mode', ['mode' => 'errors'])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $store->id, 'tab' => 'logs']));
        $this->actingAs($user)->delete($base . '/api-logs')
            ->assertRedirect(route('ext.tiktok.index', ['store' => $store->id, 'tab' => 'logs']));
        $this->actingAs($user)->post($base . '/purge-raw', ['days' => 30])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $store->id, 'tab' => 'logs']));
        $this->actingAs($user)->post($base . '/explorer/run', ['method' => 'GET', 'api_path' => '/seller/202309/shops', 'params_json' => '{}'])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $store->id, 'tab' => 'explorer']))
            ->assertSessionHas('tiktok_result');

        $html = $this->actingAs($user)->get(route('ext.tiktok.index', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Store name', $html);
        $this->assertStringContainsString('cs-store-switch', $html, 'the Active switch is the system switch');

        $this->actingAs($user)->post($base . '/save', ['env' => 'live', 'store_name' => 'TikTok PH', 'enabled' => 0])->assertRedirect();
        $store->refresh();
        $this->assertSame('TikTok PH', $store->store_name);
        $this->assertFalse((bool) $store->enabled);
    }

    public function test_a_store_standing_in_sandbox_still_sees_its_name_and_switch(): void
    {
        $store = ShopeeSetting::create(['mode' => 'sandbox', 'store_name' => 'Main store']);
        $user = $this->manager('shopee');

        $html = $this->actingAs($user)->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="sp-save-sandbox"[\s\S]*?id="sp-sb-store-name" name="store_name"[\s\S]*?Save connection/', $html);
        $this->assertStringContainsString('cs-store-switch', $html);
        $this->assertStringContainsString('on Shopee, sandbox.', $html, 'the head names the store and its environment');

        $this->actingAs($user)->post('/channels/shopee/' . $store->id . '/save', ['env' => 'sandbox', 'store_name' => 'Main store PH', 'enabled' => 1])->assertRedirect();
        $this->assertSame('Main store PH', $store->fresh()->store_name);
        $this->assertSame('sandbox', $store->fresh()->mode, 'still in sandbox');
    }

    public function test_a_fields_description_is_a_tooltip_on_its_label_row_not_a_paragraph(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);

        $html = $this->actingAs($this->manager('shopee'))
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('How this store is named across the ERP', $html);
        $this->assertStringContainsString('id="sp-store-name-hint"', $html);
        $this->assertStringContainsString('class="bl-hint fm-label__hint"', $html);
        $this->assertStringNotContainsString('class="fm-hint"', $html, 'no description paragraph under a control on this page');
        $this->assertStringContainsString('Each environment keeps its own keys and its own token', $html);
        $this->assertStringNotContainsString('cs-env__note', $html);
    }
}
