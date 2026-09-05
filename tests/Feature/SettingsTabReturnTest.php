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

/**
 * Every action on a channel's Settings page lands back on the tab its form
 * lives on (the owner, 2026-09-05): the API log controls on Logs, the status
 * maps on Status, the explorer on Explorer with its answer drawn INSIDE the
 * Explorer panel. On the store the operator was standing in - past and
 * future stores alike - and a purge touches that store's rows only.
 */
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

    // ── Shopee, two stores ───────────────────────────────────────────

    public function test_shopee_log_controls_land_on_the_logs_tab_of_the_store_they_were_pressed_on(): void
    {
        $a = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main', 'api_log_mode' => 'all']);
        $b = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet', 'api_log_mode' => 'all']);
        $user = $this->manager('shopee');
        $logsTab = route('ext.shopee.settings.show', ['store' => $b->id, 'tab' => 'logs']);

        // The mode: the second store's, and back to its Logs tab - not the
        // storeless landing that bounced onto the first store's Connection.
        $this->actingAs($user)
            ->post('/channels/shopee/' . $b->id . '/api-log-mode', ['mode' => 'errors'])
            ->assertRedirect($logsTab);
        $this->assertSame('errors', $b->fresh()->api_log_mode);
        $this->assertSame('all', $a->fresh()->api_log_mode, 'the first store is not the one the operator was standing in');

        // The purge: this store's rows only.
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

        // No credentials: the explorer answers with a result, and that result
        // is drawn inside the Explorer panel, not on Connection.
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

    // ── Lazada and TikTok ────────────────────────────────────────────

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

        // The settings page now carries the store's name and switch, and the
        // save keeps them (the columns arrived with multi-store; the controls
        // had not).
        $html = $this->actingAs($user)->get(route('ext.lazada.index', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Store name', $html);
        $this->assertStringContainsString('cs-store-switch', $html, 'the Active switch is the system switch');

        // Its own form and action: not the credential save, which also
        // rewrites the mode and the region.
        $this->actingAs($user)->post($base . '/store', ['store_name' => 'Gearshipper PH', 'enabled' => 0])
            ->assertRedirect(route('ext.lazada.index', ['store' => $store->id, 'tab' => 'connection']));
        $store->refresh();
        $this->assertSame('Gearshipper PH', $store->store_name);
        $this->assertFalse((bool) $store->enabled);
        $this->assertSame('live', $store->mode, 'the store save leaves the environment alone');
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

        $this->actingAs($user)->post($base . '/store', ['store_name' => 'TikTok PH', 'enabled' => 0])
            ->assertRedirect(route('ext.tiktok.index', ['store' => $store->id, 'tab' => 'connection']));
        $store->refresh();
        $this->assertSame('TikTok PH', $store->store_name);
        $this->assertFalse((bool) $store->enabled);
        $this->assertSame('production', $store->mode, 'the store save leaves the environment alone');
    }

    /** The bug the owner hit: the pre-multistore Shopee store runs in sandbox, and the controls lived inside the production panel. */
    public function test_a_store_standing_in_sandbox_still_sees_its_name_and_switch(): void
    {
        $store = ShopeeSetting::create(['mode' => 'sandbox', 'store_name' => 'Main store']);
        $user = $this->manager('shopee');

        $html = $this->actingAs($user)->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();

        $storeAt = strpos($html, 'id="sp-store-name"');
        $liveAt = strpos($html, 'data-cs-env-panel="live"');
        $this->assertNotFalse($storeAt);
        $this->assertLessThan($liveAt, $storeAt, 'the store section sits above both environment panels, never inside the hidden one');

        $this->actingAs($user)->post('/channels/shopee/' . $store->id . '/store', ['store_name' => 'Main store PH', 'enabled' => 1])
            ->assertRedirect(route('ext.shopee.settings.show', ['store' => $store->id, 'tab' => 'connection']));
        $this->assertSame('Main store PH', $store->fresh()->store_name);
        $this->assertSame('sandbox', $store->fresh()->mode, 'still in sandbox: the store save is not the credential save');
    }

    // ── The tooltip rule ─────────────────────────────────────────────

    public function test_a_fields_description_is_a_tooltip_on_its_label_row_not_a_paragraph(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);

        $html = $this->actingAs($this->manager('shopee'))
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()->getContent();

        // The description still reaches the reader (and the screen reader,
        // through the bubble the control points at) - as a ? beside the label.
        $this->assertStringContainsString('How this store is named across the ERP', $html);
        $this->assertStringContainsString('id="sp-store-name-hint"', $html);
        $this->assertStringContainsString('class="bl-hint fm-label__hint"', $html);
        $this->assertStringNotContainsString('class="fm-hint"', $html, 'no description paragraph under a control on this page');
        // The environment note rides the same button.
        $this->assertStringContainsString('Every sync, push and order fetch uses the credentials of the environment selected here', $html);
        $this->assertStringNotContainsString('cs-env__note', $html);
    }
}
