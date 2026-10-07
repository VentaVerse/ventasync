<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeListingReadiness;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreSetupTest extends TestCase
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
        $group = UserGroup::create(['name' => ucfirst($channel) . ' setup manager ' . uniqid()]);
        $group->permissions()->attach(Permission::where('key', 'like', '%_' . $channel . '/%')->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => 1,
            'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_settings_page_carries_the_setup_popup_and_starts_it_after_an_authorisation(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);
        $user = $this->manager('shopee');

        $html = $this->actingAs($user)->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-channel-setup', $html);
        $this->assertStringContainsString('data-setup-run="0"', $html, 'a plain visit does not start the run');
        $this->assertStringContainsString('data-setup-open', $html, 'Set up store re-runs it on demand');
        $this->assertStringContainsString('/setup/categories', $html);
        $this->assertStringContainsString('/setup/couriers', $html);

        $html = $this->actingAs($user)->withSession(['settings_setup' => true])
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-setup-run="1"', $html);
    }

    public function test_a_setup_step_on_an_unconnected_store_answers_a_plain_sentence(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);

        $r = $this->actingAs($this->manager('shopee'))
            ->postJson('/channels/shopee/' . $store->id . '/setup/categories');

        $r->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('not connected yet', $r->json('message'));
        $this->assertStringNotContainsString('{', $r->json('message'), 'a sentence, never a payload');

        $status = $this->actingAs($this->manager('shopee'))
            ->postJson('/channels/shopee/' . $store->id . '/setup/anything-else')->status();
        $this->assertContains($status, [404, 405]);
    }

    public function test_a_connection_result_is_one_plain_line_and_never_a_payload(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);
        $user = $this->manager('shopee');

        $html = $this->actingAs($user)
            ->withSession(['settings_tab' => 'connection', 'shopee_result' => ['ok' => true, 'title' => 'Token exchange', 'data' => ['body' => ['access_token' => 'SECRET-DO-NOT-SHOW']]]])
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Token exchange succeeded.', $html);
        $this->assertStringNotContainsString('SECRET-DO-NOT-SHOW', $html, 'the payload stays off the page');
        $this->assertStringNotContainsString('Full response', $html);

        $html = $this->actingAs($user)
            ->withSession(['settings_tab' => 'connection', 'shopee_result' => ['ok' => false, 'title' => 'Authorisation', 'data' => ['ok' => false, 'body' => ['error' => 'error_auth', 'message' => 'Invalid code.', 'request_id' => 'req-9']]]])
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Authorisation did not go through.', $html);
        $this->assertStringContainsString('See the API log', $html);
        $this->assertStringNotContainsString('req-9', $html, 'the raw answer is for the API log, not the page');
        $this->assertStringNotContainsString('cs-pre', $html);
    }

    public function test_readiness_says_when_nothing_has_been_fetched_yet(): void
    {
        ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main']);
        $pid = $this->seedProduct('Bare widget', 'BARE-1');
        ShopeeListing::create(['product_id' => $pid]);

        $missing = app(ShopeeListingReadiness::class)->forProducts([$pid])[$pid]['missing'];
        $this->assertStringContainsString('none have been fetched for this store yet', implode(' | ', $missing));
        $this->assertStringContainsString('Catalog > Categories', implode(' | ', $missing));
        $this->assertStringContainsString('Catalog > Logistics', implode(' | ', $missing));

        ShopeeCategory::create(['category_id' => 100200, 'parent_id' => null, 'name' => 'Pedals', 'level' => 0, 'leaf' => true]);
        ShopeeLogistic::create(['logistics_channel_id' => 8001, 'logistics_channel_name' => 'J&T', 'enabled' => true]);
        $missing = app(ShopeeListingReadiness::class)->forProducts([$pid])[$pid]['missing'];
        $this->assertStringNotContainsString('none have been fetched', implode(' | ', $missing));
        $this->assertContains('a Shopee category', $missing);
        $this->assertContains('at least one courier', $missing);
    }

    public function test_lazada_and_tiktok_readiness_say_when_no_categories_were_fetched(): void
    {
        LazadaSetting::create(['mode' => 'live', 'store_name' => 'Laz']);
        TikTokSetting::create(['mode' => 'production', 'store_name' => 'TT', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3)]);
        $pid = $this->seedProduct('Bare widget', 'BARE-1');
        \Extensions\lazada\Models\LazadaProduct::create(['product_id' => $pid]);
        \Extensions\tiktok\Models\TikTokListing::create(['product_id' => $pid]);

        $laz = app(\Extensions\lazada\Services\Lazada\LazadaListingReadiness::class)->forProducts([$pid])[$pid]['missing'];
        $this->assertStringContainsString('none have been fetched for this store yet', implode(' | ', $laz));

        $tt = app(\Extensions\tiktok\Services\TikTok\TikTokListingReadiness::class)->forProducts([$pid])[$pid]['missing'];
        $this->assertStringContainsString('none have been fetched for this store yet', implode(' | ', $tt));
    }
}
