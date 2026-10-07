<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\ShopeeExtension;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\opencart\Models\OpenCartOrderStatusMap;
use Extensions\opencart\Models\OpenCartProductGroup;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use Extensions\opencart\OpencartExtension;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TiktokExtension;
use Extensions\ventacart\Models\VentaCartApiLog;
use Extensions\ventacart\Models\VentaCartOrderStatusMap;
use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Models\VentaCartSyncLog;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceInteriorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $manager->install('lazada');
        $manager->enable('lazada');
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $manager->install('opencart');
        $manager->enable('opencart');

        $this->app->register(ShopeeExtension::class);
        $this->app->register(LazadaExtension::class);
        $this->app->register(TiktokExtension::class);
        $this->app->register(VentaCartExtension::class);
        $this->app->register(OpencartExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Viewer']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/dashboard'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Manager']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/settings', 'manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/product_group', 'manage_shopee/product', 'manage_shopee/dashboard'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function outsider(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Outsider']);

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function assertDenied(\Illuminate\Testing\TestResponse $response, string $context): void
    {
        if ($response->getStatusCode() === 403) {
            $response->assertSee('You are not allowed to access this page');

            return;
        }

        $response->assertRedirect();
        $response->assertSessionHas('error', "You don't have permission to do this action.");
        $this->assertTrue(true, $context);
    }

    private function interiorUrls(): array
    {
        if (ShopeeSetting::query()->count() === 0) {
            $this->seedSetting();
        }

        return [
            'settings'       => route('ext.shopee.settings.show'),
            'listings'       => route('ext.shopee.products.index'),
            'product-groups' => route('ext.shopee.product-groups.index'),
            'categories'     => route('ext.shopee.categories.index'),
            'logistics'      => route('ext.shopee.logistics.index'),
        ];
    }

    private function seedSetting(array $overrides = []): ShopeeSetting
    {
        return ShopeeSetting::create(array_merge([
            'mode' => 'live',
            'partner_id' => 200123,
            'partner_key' => encrypt('live-partner-key'),
            'shop_id' => 900456,
            'access_token' => encrypt('live-access-token'),
            'region' => 'ph',
            'api_log_mode' => 'all',
        ], $overrides));
    }

    public function test_every_interior_renders_for_the_manage_tier(): void
    {
        $this->seedSetting();
        $user = $this->manager();

        foreach ($this->interiorUrls() as $label => $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_every_interior_renders_for_the_read_tier(): void
    {
        $this->seedSetting();
        $user = $this->viewer();

        foreach ($this->interiorUrls() as $label => $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_no_interior_still_carries_the_legacy_boundary(): void
    {
        $this->seedSetting();
        $user = $this->manager();

        foreach ($this->interiorUrls() as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'class="x-content x-legacy"',
                $html,
                "{$label} is still inside the .x-legacy content boundary."
            );
            $this->assertStringContainsString(
                'class="x-content min-w-0 flex-1',
                $html,
                "{$label} did not render the shared content region."
            );
        }
    }

    public function test_interiors_render_their_seeded_records(): void
    {
        $this->seedSetting();

        ShopeeCategory::create([
            'category_id' => 100777,
            'parent_id' => null,
            'name' => 'Guitars & Amplifiers',
            'level' => 0,
            'leaf' => false,
        ]);

        ShopeeLogistic::create([
            'logistics_channel_id' => 88017,
            'logistics_channel_name' => 'J&T Express',
            'enabled' => true,
            'cod_enabled' => true,
            'force_enable' => false,
            'mask_channel_id' => 0,
            'support_pre_order' => false,
            'weight_limit' => ['item_min_weight' => 0.1, 'item_max_weight' => 5],
            'item_max_dimension' => ['length' => 40, 'width' => 30, 'height' => 20, 'unit' => 'cm'],
        ]);

        ShopeeProductGroup::create([
            'name' => 'Pedals & Effects',
            'shopee_category_id' => 100777,
            'markup_fixed' => 25,
            'markup_percent' => 7.5,
        ]);

        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.shopee.categories.index'))
            ->assertOk()
            ->assertSee('Guitars &amp; Amplifiers', false);

        $this->actingAs($user)->get(route('ext.shopee.logistics.index'))
            ->assertOk()
            ->assertSee('J&amp;T Express', false)
            ->assertSee('88017');

        $this->actingAs($user)->get(route('ext.shopee.product-groups.index'))
            ->assertOk()
            ->assertSee('Pedals &amp; Effects', false);
    }

    public function test_channel_sourced_names_are_escaped(): void
    {
        $this->seedSetting();

        ShopeeCategory::create([
            'category_id' => 100888,
            'parent_id' => null,
            'name' => '<script>alert(1)</script>',
            'level' => 0,
            'leaf' => true,
        ]);

        ShopeeLogistic::create([
            'logistics_channel_id' => 88018,
            'logistics_channel_name' => '<img src=x onerror=alert(2)>',
            'enabled' => true,
        ]);

        ShopeeProductGroup::create(['name' => '<b>bold group</b>']);

        $xssProduct = $this->seedCatalogProduct('<svg onload=alert(4)>', 'CS-XSS-1');
        \Extensions\shopee\Models\ShopeeListing::create(['product_id' => $xssProduct]);
        ShopeeApiLog::create([
            'method' => 'GET', 'api_path' => '/api/v2/<i>alert(5)</i>',
            'auth_required' => true, 'status_code' => 200, 'response_time_ms' => 10,
            'ok' => true, 'request_body' => null, 'response_body' => [],
        ]);

        $user = $this->manager();

        foreach ([
            route('ext.shopee.categories.index') => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            route('ext.shopee.logistics.index') => ['<img src=x onerror=alert(2)>', '&lt;img src=x onerror=alert(2)&gt;'],
            route('ext.shopee.product-groups.index') => ['<b>bold group</b>', '&lt;b&gt;bold group&lt;/b&gt;'],
            route('ext.shopee.products.index') => ['<svg onload=alert(4)>', '&lt;svg onload=alert(4)&gt;'],
            route('ext.shopee.settings.show') => ['<i>alert(5)</i>', '&lt;i&gt;alert(5)&lt;/i&gt;'],
        ] as $url => [$raw, $escaped]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($raw, $html, "Unescaped output on {$url}");
            $this->assertStringContainsString($escaped, $html, "Payload never reached {$url} at all");
        }
    }

    public function test_an_operator_with_no_shopee_permission_is_refused_every_interior(): void
    {
        $user = $this->outsider();

        foreach ($this->interiorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), $label);
        }
    }

    public function test_guests_are_redirected_away_from_every_interior(): void
    {
        foreach ($this->interiorUrls() as $label => $url) {
            $this->get($url)->assertRedirect();
        }
    }

    public function test_the_read_tier_is_not_offered_write_controls(): void
    {
        $this->seedSetting();
        ShopeeApiLog::create([
            'method' => 'GET', 'api_path' => '/api/v2/shop/get_shop_info',
            'auth_required' => true, 'status_code' => 200, 'response_time_ms' => 10,
            'ok' => true, 'request_body' => null, 'response_body' => [],
        ]);
        $user = $this->viewer();

        $categories = $this->actingAs($user)->get(route('ext.shopee.categories.index'))->assertOk();
        $categories->assertDontSee(route('ext.shopee.categories.fetch'), false);

        $logistics = $this->actingAs($user)->get(route('ext.shopee.logistics.index'))->assertOk();
        $logistics->assertDontSee(route('ext.shopee.logistics.fetch'), false);

        $groups = $this->actingAs($user)->get(route('ext.shopee.product-groups.index'))->assertOk();
        $groups->assertDontSee(route('ext.shopee.product-groups.create'), false);

        $listings = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk();

        $settings = $this->actingAs($user)->get(route('ext.shopee.settings.show'))->assertOk();
        $settings->assertDontSee(route('ext.shopee.toggle_mode'), false);
        $settings->assertDontSee(route('ext.shopee.token_get'), false);
        $settings->assertDontSee(route('ext.shopee.token_refresh'), false);
        $settings->assertDontSee(route('ext.shopee.authorize'), false);
        $settings->assertDontSee(route('ext.shopee.purge_raw'), false);
        $settings->assertDontSee(route('ext.shopee.explorer_run'), false);
        $settings->assertDontSee(route('ext.shopee.clear_api_logs'), false);
        $settings->assertDontSee('Save production settings');
        $settings->assertDontSee('Save order mapping');

        $html = $settings->getContent();
        $this->assertStringContainsString('name="partner_key"', $html, 'The read tier lost the credential field entirely.');
        foreach ([
            'partner_id', 'partner_key', 'shop_id', 'access_token', 'region',
            'sandbox_partner_id', 'sandbox_partner_key', 'sandbox_shop_id',
            'sandbox_access_token', 'sandbox_region',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
    }

    public function test_the_manage_tier_is_offered_those_same_controls(): void
    {
        $this->seedSetting();
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.shopee.categories.index'))
            ->assertOk()->assertSee(route('ext.shopee.categories.fetch'), false);

        $this->actingAs($user)->get(route('ext.shopee.logistics.index'))
            ->assertOk()->assertSee(route('ext.shopee.logistics.fetch'), false);

        $this->actingAs($user)->get(route('ext.shopee.product-groups.index'))
            ->assertOk()->assertSee(route('ext.shopee.product-groups.create'), false);

        $this->actingAs($user)->get(route('ext.shopee.products.index'))
            ->assertOk();

        $this->actingAs($user)->get(route('ext.shopee.products.import'))
            ->assertOk()
            ->assertSee('Fetch from Shopee');

        $this->actingAs($user)->get(route('ext.shopee.settings.show'))
            ->assertOk()
            ->assertSee(route('ext.shopee.save'), false)
            ->assertSee(route('ext.shopee.token_get'), false);
    }

    public function test_the_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        $this->seedSetting();
        $user = $this->viewer();

        $posts = [
            route('ext.shopee.save'),
            route('ext.shopee.toggle_mode'),
            route('ext.shopee.token_get'),
            route('ext.shopee.token_refresh'),
            route('ext.shopee.order_status_map'),
            route('ext.shopee.return_status_map'),
            route('ext.shopee.api_log_mode'),
            route('ext.shopee.purge_raw'),
            route('ext.shopee.categories.fetch'),
            route('ext.shopee.logistics.fetch'),
            route('ext.shopee.products.bulk_delete'),
            route('ext.shopee.products.import_one'),
            route('ext.shopee.products.rebuild_cache'),
        ];

        foreach ($posts as $url) {
            $this->assertDenied($this->actingAs($user)->post($url, []), $url);
        }

        $this->assertDenied($this->actingAs($user)->delete(route('ext.shopee.clear_api_logs')), 'clear logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.shopee.product-groups.create')), 'group create');

        $this->assertSame('live', ShopeeSetting::query()->first()->mode);
    }

    public function test_the_save_gate_separates_the_two_tiers_on_one_route(): void
    {
        $this->seedSetting();

        $payload = ['env' => 'live', 'partner_id' => 111222];

        $this->assertDenied(
            $this->actingAs($this->viewer())->post(route('ext.shopee.save'), $payload),
            'save as viewer'
        );

        $this->assertSame(200123, (int) ShopeeSetting::query()->first()->partner_id);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.save'), $payload)
            ->assertRedirect(route('ext.shopee.settings.show'));

        $this->assertSame(111222, (int) ShopeeSetting::query()->first()->partner_id);
    }

    public function test_the_settings_save_round_trips_every_validated_field(): void
    {
        $this->seedSetting(['mode' => 'sandbox', 'partner_id' => null, 'partner_key' => null, 'shop_id' => null, 'access_token' => null, 'region' => null]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.save'), [
            'env' => 'live',
            'partner_id' => 200999,
            'partner_key' => 'the-live-partner-key',
            'shop_id' => 900999,
            'access_token' => 'the-live-access-token',
            'region' => 'ph',
        ])->assertRedirect(route('ext.shopee.settings.show'))->assertSessionHas('status');

        $row = ShopeeSetting::query()->first();
        $this->assertSame('live', $row->mode);
        $this->assertSame(200999, (int) $row->partner_id);
        $this->assertSame(900999, (int) $row->shop_id);
        $this->assertSame('ph', $row->region);
        $this->assertSame('the-live-partner-key', decrypt($row->partner_key));
        $this->assertSame('the-live-access-token', decrypt($row->access_token));

        $this->actingAs($user)->post(route('ext.shopee.save'), [
            'env' => 'sandbox',
            'sandbox_partner_id' => 100111,
            'sandbox_partner_key' => 'the-sandbox-partner-key',
            'sandbox_shop_id' => 800111,
            'sandbox_access_token' => 'the-sandbox-access-token',
            'sandbox_region' => 'sg',
        ])->assertRedirect(route('ext.shopee.settings.show'));

        $row->refresh();
        $this->assertSame('sandbox', $row->mode);
        $this->assertSame(100111, (int) $row->sandbox_partner_id);
        $this->assertSame(800111, (int) $row->sandbox_shop_id);
        $this->assertSame('sg', $row->sandbox_region);
        $this->assertSame('the-sandbox-partner-key', decrypt($row->sandbox_partner_key));
        $this->assertSame('the-sandbox-access-token', decrypt($row->sandbox_access_token));

        $this->assertSame(200999, (int) $row->partner_id);
        $this->assertSame('the-live-partner-key', decrypt($row->partner_key));
    }

    public function test_a_save_that_omits_the_masked_secrets_keeps_them(): void
    {
        $this->seedSetting();
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.save'), [
            'env' => 'live',
            'partner_id' => '',
            'partner_key' => '',
            'shop_id' => '',
            'access_token' => '',
            'region' => 'sg',
        ])->assertRedirect(route('ext.shopee.settings.show'));

        $row = ShopeeSetting::query()->first();
        $this->assertSame('live-partner-key', decrypt($row->partner_key));
        $this->assertSame('live-access-token', decrypt($row->access_token));
        $this->assertSame(200123, (int) $row->partner_id);
        $this->assertSame(900456, (int) $row->shop_id);
        $this->assertSame('sg', $row->region);
    }

    public function test_the_environment_switch_posts_the_mode_it_names(): void
    {
        $this->seedSetting(['mode' => 'live']);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.toggle_mode'), ['mode' => 'sandbox'])
            ->assertRedirect(route('ext.shopee.settings.show'));

        $this->assertSame('sandbox', ShopeeSetting::query()->first()->mode);

        $this->actingAs($user)->post(route('ext.shopee.toggle_mode'), ['mode' => 'live'])
            ->assertRedirect(route('ext.shopee.settings.show'));

        $this->assertSame('live', ShopeeSetting::query()->first()->mode);
    }

    public function test_the_log_mode_control_saves_and_returns_to_the_panel(): void
    {
        $this->seedSetting(['api_log_mode' => 'all']);
        $user = $this->manager();

        $this->actingAs($user)
            ->post(route('ext.shopee.api_log_mode'), ['mode' => 'errors'])
            ->assertRedirect(route('ext.shopee.settings.show', ['store' => ShopeeSetting::query()->first()->id, 'tab' => 'logs']));

        $this->assertSame('errors', ShopeeSetting::query()->first()->api_log_mode);

        $this->actingAs($user)
            ->post(route('ext.shopee.api_log_mode'), ['mode' => 'loud'])
            ->assertSessionHasErrors('mode');
        $this->assertSame('errors', ShopeeSetting::query()->first()->api_log_mode);
    }

    public function test_the_oauth_callback_still_exchanges_the_code_on_this_url(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expire_in' => 14400,
            ], 200),
        ]);

        $this->seedSetting([
            'mode' => 'live',
            'shop_id' => null,
            'access_token' => null,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index', ['code' => 'the-auth-code', 'shop_id' => 900456]));

        $response->assertRedirect(route('ext.shopee.settings.show'));
        $response->assertSessionHas('shopee_result');

        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return $request->method() === 'POST'
                && str_starts_with($url, 'https://partner.shopeemobile.com/api/v2/auth/token/get?')
                && str_contains($url, 'partner_id=200123')
                && str_contains($url, 'timestamp=')
                && str_contains($url, 'sign=')
                && $request['code'] === 'the-auth-code'
                && (int) $request['shop_id'] === 900456
                && (int) $request['partner_id'] === 200123;
        });

        $row = ShopeeSetting::query()->first();
        $this->assertSame(900456, (int) $row->shop_id, 'shop_id from the callback was not persisted.');
        $this->assertSame('fresh-access-token', decrypt($row->access_token));
        $this->assertSame('fresh-refresh-token', decrypt($row->refresh_token));
        $this->assertNotNull($row->expires_at);
    }

    public function test_the_oauth_callback_stays_inside_the_active_environment(): void
    {
        Http::fake([
            'openplatform.sandbox.test-stable.shopee.sg/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'sandbox-access-token',
                'refresh_token' => 'sandbox-refresh-token',
                'expire_in' => 14400,
            ], 200),
        ]);

        $this->seedSetting([
            'mode' => 'sandbox',
            'sandbox_partner_id' => 100111,
            'sandbox_partner_key' => encrypt('sandbox-partner-key'),
            'sandbox_shop_id' => null,
        ]);

        $this->actingAs($this->manager())
            ->get(route('ext.shopee.index', ['code' => 'sandbox-code', 'shop_id' => 800111]))
            ->assertRedirect(route('ext.shopee.settings.show'));

        Http::assertSent(function ($request) {
            return str_starts_with(
                $request->url(),
                'https://openplatform.sandbox.test-stable.shopee.sg/api/v2/auth/token/get?'
            ) && $request['code'] === 'sandbox-code';
        });

        $row = ShopeeSetting::query()->first();
        $this->assertSame(800111, (int) $row->sandbox_shop_id);
        $this->assertSame('sandbox-access-token', decrypt($row->sandbox_access_token));
        $this->assertSame('live-access-token', decrypt($row->access_token), 'A sandbox callback overwrote the live token.');
    }

    public function test_a_plain_settings_visit_calls_shopee_at_all(): void
    {
        Http::fake();

        $this->seedSetting();

        $this->actingAs($this->manager())->get(route('ext.shopee.settings.show'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_redirect_uri_is_shown_but_not_submittable(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.settings.show'))->assertOk()->getContent();

        $redirect = preg_replace('/^http:/i', 'https:', route('ext.shopee.index'));

        $this->assertStringContainsString(e($redirect), $html);
        $this->assertSame(2, substr_count($html, 'value="'.e($redirect).'"'));

        preg_match_all('/<input[^>]*value="'.preg_quote(e($redirect), '/').'"[^>]*>/', $html, $matches);
        $this->assertCount(2, $matches[0]);
        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('readonly', $tag);
            $this->assertStringNotContainsString('name=', $tag);
        }

        $this->assertStringNotContainsString('name="redirect_uri"', $html);
        $this->assertStringNotContainsString('name="sandbox_redirect_uri"', $html);
    }

    public function test_the_settings_page_still_carries_every_oauth_control(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.settings.show'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.e(route('ext.shopee.authorize', ['store' => ShopeeSetting::query()->value('id')])).'"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.shopee.token_get')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.shopee.token_refresh')).'"', $html);
        $this->assertStringContainsString('name="code"', $html);
    }

    public function test_the_settings_page_carries_the_whole_field_census(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.settings.show'))->assertOk()->getContent();

        foreach ([
            'env', 'partner_id', 'partner_key', 'shop_id', 'access_token', 'region',
            'sandbox_partner_id', 'sandbox_partner_key', 'sandbox_shop_id',
            'sandbox_access_token', 'sandbox_region',
            'mode', 'days', 'method', 'api_path', 'use_access_token', 'use_shop_id',
            'params_json', 'pack',
        ] as $field) {
            $this->assertStringContainsString(
                'name="'.$field.'"',
                $html,
                "The settings page no longer carries a field named {$field}."
            );
        }

        $this->assertStringContainsString('name="map[READY_TO_SHIP]"', $html);
        $this->assertStringContainsString('name="map[REFUND_PAID]"', $html);
    }

    public function test_each_shopee_credential_form_carries_its_own_env_value(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.settings.show'))->assertOk()->getContent();

        $saveAction = 'action="'.e(route('ext.shopee.save')).'"';
        preg_match_all('#<form[^>]*'.preg_quote($saveAction, '#').'.*?</form>#s', $html, $matches);
        $this->assertCount(2, $matches[0], 'Expected exactly two Shopee credential-save forms.');

        [$liveForm, $sandboxForm] = $matches[0];

        $this->assertStringContainsString('name="partner_id"', $liveForm);
        $this->assertStringNotContainsString('name="sandbox_partner_id"', $liveForm);
        $this->assertStringContainsString('name="env" value="live"', $liveForm);
        $this->assertStringNotContainsString('value="sandbox"', $liveForm,
            'The production credential form carried the sandbox env value.');

        $this->assertStringContainsString('name="sandbox_partner_id"', $sandboxForm);
        $this->assertStringContainsString('name="env" value="sandbox"', $sandboxForm);
        $this->assertStringNotContainsString('value="live"', $sandboxForm,
            'The sandbox credential form carried the live env value, which would overwrite production on save.');
    }

    public function test_stored_secrets_never_reach_the_page_source(): void
    {
        $this->seedSetting([
            'sandbox_partner_key' => encrypt('sandbox-partner-key'),
            'sandbox_access_token' => encrypt('sandbox-access-token'),
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.settings.show'))->assertOk()->getContent();

        foreach ([
            'live-partner-key', 'live-access-token',
            'sandbox-partner-key', 'sandbox-access-token',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $this->assertStringContainsString('data-credential-mask="1"', $html);
    }

    public function test_the_api_log_tab_renders_a_recorded_call(): void
    {
        $this->seedSetting();

        ShopeeApiLog::create([
            'method' => 'GET',
            'api_path' => '/api/v2/shop/get_shop_info',
            'auth_required' => true,
            'request_params' => ['language' => 'en'],
            'response_status' => 200,
            'ok' => true,
            'response_body' => ['response' => ['shop_name' => 'Test & Co']],
        ]);

        $this->actingAs($this->manager())->get(route('ext.shopee.settings.show'))
            ->assertOk()
            ->assertSee('/api/v2/shop/get_shop_info')
            ->assertSee('action="'.e(route('ext.shopee.clear_api_logs')).'"', false);
    }

    private function lazadaViewer(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Interiors Viewer']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/dashboard'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function lazadaManager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Interiors Manager']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function lazadaInteriorUrls(): array
    {
        if (LazadaSetting::query()->count() === 0) {
            $this->seedLazadaSetting();
        }

        return [
            'settings'       => route('ext.lazada.index'),
            'listings'       => route('ext.lazada.products.index'),
            'product-groups' => route('ext.lazada.product-groups.index'),
            'categories'     => route('ext.lazada.categories.index'),
            'brands'         => route('ext.lazada.brands.index'),
        ];
    }

    private function seedLazadaSetting(array $overrides = []): LazadaSetting
    {
        return LazadaSetting::create(array_merge([
            'mode' => 'live',
            'region' => 'ph',
            'app_key' => '100123',
            'app_secret' => encrypt('live-app-secret'),
            'access_token' => encrypt('live-access-token'),
            'api_log_mode' => 'all',
        ], $overrides));
    }

    public function test_every_lazada_interior_renders_for_the_manage_tier(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaManager();

        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_every_lazada_interior_renders_for_the_read_tier(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaViewer();

        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_no_lazada_interior_still_carries_the_legacy_boundary(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaManager();

        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'class="x-content x-legacy"',
                $html,
                "Lazada {$label} is still inside the .x-legacy content boundary."
            );
            $this->assertStringContainsString(
                'class="x-content min-w-0 flex-1',
                $html,
                "Lazada {$label} did not render the shared content region."
            );
        }
    }

    public function test_lazada_interiors_render_their_seeded_records(): void
    {
        $this->seedLazadaSetting();

        LazadaCategory::create([
            'category_id' => 9257,
            'parent_id' => null,
            'name' => 'Guitars & Amplifiers',
            'level' => 0,
            'leaf' => false,
            'var' => true,
        ]);

        LazadaBrand::create([
            'region' => 'ph',
            'brand_id' => 4021,
            'name' => 'Morley & Sons',
        ]);

        LazadaProductGroup::create([
            'name' => 'Pedals & Effects',
            'lazada_category_id' => 9257,
            'markup_fixed' => 25,
            'markup_percent' => 7.5,
        ]);

        $listing = LazadaProduct::create([
            'product_id' => 4242,
            'primary_category_id' => 9257,
            'lazada_item_id' => '778899',
        ]);

        $user = $this->lazadaManager();

        $this->actingAs($user)->get(route('ext.lazada.categories.index'))
            ->assertOk()
            ->assertSee('Guitars &amp; Amplifiers', false)
            ->assertSee('9257');

        $this->actingAs($user)->get(route('ext.lazada.brands.index'))
            ->assertOk()
            ->assertSee('Morley &amp; Sons', false)
            ->assertSee('4021');

        $this->actingAs($user)->get(route('ext.lazada.product-groups.index'))
            ->assertOk()
            ->assertSee('Pedals &amp; Effects', false);

        $this->actingAs($user)->get(route('ext.lazada.products.index'))
            ->assertOk()
            ->assertDontSee('778899')
            ->assertDontSee('deleted catalog product');
    }

    public function test_lazada_channel_sourced_names_are_escaped(): void
    {
        $this->seedLazadaSetting();

        LazadaCategory::create([
            'category_id' => 9258,
            'parent_id' => null,
            'name' => '<script>alert(1)</script>',
            'level' => 0,
            'leaf' => true,
        ]);

        LazadaBrand::create([
            'region' => 'ph',
            'brand_id' => 4022,
            'name' => '<img src=x onerror=alert(2)>',
        ]);

        LazadaProductGroup::create(['name' => '<b>bold group</b>']);


        $user = $this->lazadaManager();

        foreach ([
            route('ext.lazada.categories.index'),
            route('ext.lazada.brands.index'),
            route('ext.lazada.product-groups.index'),
            route('ext.lazada.products.index'),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
            $this->assertStringNotContainsString('<b>bold group</b>', $html);
            $this->assertStringNotContainsString('<svg onload=alert(3)>', $html);
        }
    }

    public function test_an_operator_with_no_lazada_permission_is_refused_every_interior(): void
    {
        $user = $this->outsider();

        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), 'lazada '.$label);
        }
    }

    public function test_guests_are_redirected_away_from_every_lazada_interior(): void
    {
        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->get($url)->assertRedirect();
        }
    }

    public function test_the_other_channels_manage_tier_opens_nothing_here(): void
    {
        $this->seedLazadaSetting();
        $this->seedSetting();

        $shopeeManager = $this->manager();
        $lazadaManager = $this->lazadaManager();

        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($shopeeManager)->get($url), 'shopee manager on lazada '.$label);
        }

        foreach ($this->interiorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($lazadaManager)->get($url), 'lazada manager on shopee '.$label);
        }
    }

    public function test_the_lazada_read_tier_is_not_offered_write_controls(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaViewer();

        $categories = $this->actingAs($user)->get(route('ext.lazada.categories.index'))->assertOk();
        $categories->assertDontSee(route('ext.lazada.categories.fetch'), false);

        $brands = $this->actingAs($user)->get(route('ext.lazada.brands.index'))->assertOk();
        $brands->assertDontSee(route('ext.lazada.brands.fetch'), false);

        $groups = $this->actingAs($user)->get(route('ext.lazada.product-groups.index'))->assertOk();
        $groups->assertDontSee(route('ext.lazada.product-groups.create'), false);
        $groups->assertDontSee('Delete group');

        $listings = $this->actingAs($user)->get(route('ext.lazada.products.index'))->assertOk();
        $listings->assertDontSee(route('ext.lazada.products.bulk_delete'), false);
        $listings->assertDontSee(route('ext.lazada.products.edit', ['productId' => 1]), false);
        $listings->assertDontSee(route('ext.lazada.products.import_one'), false);

        $settings = $this->actingAs($user)->get(route('ext.lazada.index'))->assertOk();
        $settings->assertDontSee(route('ext.lazada.toggle_mode'), false);
        $settings->assertDontSee(route('ext.lazada.token_create'), false);
        $settings->assertDontSee(route('ext.lazada.token_refresh'), false);
        $settings->assertDontSee(route('ext.lazada.sandbox_token_save'), false);
        $settings->assertDontSee(route('ext.lazada.authorize'), false);
        $settings->assertDontSee(route('ext.lazada.purge_raw'), false);
        $settings->assertDontSee(route('ext.lazada.explorer_run'), false);
        $settings->assertDontSee(route('ext.lazada.clear_api_logs'), false);
        $settings->assertDontSee('Save production settings');
        $settings->assertDontSee('Save order mapping');

        $html = $settings->getContent();
        $this->assertStringContainsString('name="app_secret"', $html, 'The read tier lost the credential field entirely.');
        foreach (['region', 'app_key', 'app_secret', 'sandbox_app_key', 'sandbox_app_secret'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
    }

    public function test_the_lazada_manage_tier_is_offered_those_same_controls(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaManager();

        $this->actingAs($user)->get(route('ext.lazada.categories.index'))
            ->assertOk()->assertSee(route('ext.lazada.categories.fetch'), false);

        $this->actingAs($user)->get(route('ext.lazada.brands.index'))
            ->assertOk()->assertSee(route('ext.lazada.brands.fetch'), false);

        $this->actingAs($user)->get(route('ext.lazada.product-groups.index'))
            ->assertOk()->assertSee(route('ext.lazada.product-groups.create'), false);

        $this->actingAs($user)->get(route('ext.lazada.products.index'))
            ->assertOk();

        $this->actingAs($user)->get(route('ext.lazada.products.import'))
            ->assertOk()
            ->assertSee('Fetch from Lazada');

        $this->actingAs($user)->get(route('ext.lazada.index'))
            ->assertOk()
            ->assertSee(route('ext.lazada.save'), false)
            ->assertSee(route('ext.lazada.token_create'), false);
    }

    public function test_the_lazada_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaViewer();

        $posts = [
            route('ext.lazada.save'),
            route('ext.lazada.toggle_mode'),
            route('ext.lazada.token_create'),
            route('ext.lazada.token_refresh'),
            route('ext.lazada.sandbox_token_save'),
            route('ext.lazada.order_status_map'),
            route('ext.lazada.reverse_status_map'),
            route('ext.lazada.api_log_mode'),
            route('ext.lazada.purge_raw'),
            route('ext.lazada.explorer_run'),
            route('ext.lazada.categories.fetch'),
            route('ext.lazada.brands.fetch'),
            route('ext.lazada.products.bulk_delete'),
            route('ext.lazada.products.import_one'),
        ];

        foreach ($posts as $url) {
            $this->assertDenied($this->actingAs($user)->post($url, []), $url);
        }

        $this->assertDenied($this->actingAs($user)->delete(route('ext.lazada.clear_api_logs')), 'clear logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.product-groups.create')), 'group create');
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.products.edit', ['productId' => 1])), 'listing create');
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.authorize')), 'authorize');

        $this->assertSame('live', LazadaSetting::query()->first()->mode);
    }

    public function test_the_lazada_save_gate_separates_the_two_tiers_on_one_route(): void
    {
        $this->seedLazadaSetting();

        $payload = ['env' => 'live', 'region' => 'sg', 'app_key' => '100999'];

        $this->assertDenied(
            $this->actingAs($this->lazadaViewer())->post(route('ext.lazada.save'), $payload),
            'save as viewer'
        );

        $this->assertSame('100123', (string) LazadaSetting::query()->first()->app_key);
        $this->assertSame('ph', LazadaSetting::query()->first()->region);

        $this->actingAs($this->lazadaManager())
            ->post(route('ext.lazada.save'), $payload)
            ->assertRedirect(route('ext.lazada.index'));

        $this->assertSame('100999', (string) LazadaSetting::query()->first()->app_key);
        $this->assertSame('sg', LazadaSetting::query()->first()->region);
    }

    public function test_the_lazada_settings_save_round_trips_every_validated_field(): void
    {
        $this->seedLazadaSetting([
            'mode' => 'sandbox', 'region' => null, 'app_key' => null, 'app_secret' => null, 'access_token' => null,
        ]);
        $user = $this->lazadaManager();

        $this->actingAs($user)->post(route('ext.lazada.save'), [
            'env' => 'live',
            'region' => 'ph',
            'app_key' => '100777',
            'app_secret' => 'the-live-app-secret',
        ])->assertRedirect(route('ext.lazada.index'))->assertSessionHas('status');

        $row = LazadaSetting::query()->first();
        $this->assertSame('live', $row->mode);
        $this->assertSame('ph', $row->region);
        $this->assertSame('100777', (string) $row->app_key);
        $this->assertSame('the-live-app-secret', decrypt($row->app_secret));

        $this->actingAs($user)->post(route('ext.lazada.save'), [
            'env' => 'sandbox',
            'region' => 'sg',
            'sandbox_app_key' => '200777',
            'sandbox_app_secret' => 'the-sandbox-app-secret',
        ])->assertRedirect(route('ext.lazada.index'));

        $row->refresh();
        $this->assertSame('sandbox', $row->mode);
        $this->assertSame('200777', (string) $row->sandbox_app_key);
        $this->assertSame('the-sandbox-app-secret', decrypt($row->sandbox_app_secret));

        $this->assertSame('sg', $row->region);

        $this->assertSame('100777', (string) $row->app_key);
        $this->assertSame('the-live-app-secret', decrypt($row->app_secret));
    }

    public function test_a_lazada_save_that_omits_the_masked_secret_keeps_it(): void
    {
        $this->seedLazadaSetting();
        $user = $this->lazadaManager();

        $this->actingAs($user)->post(route('ext.lazada.save'), [
            'env' => 'live',
            'region' => 'vn',
            'app_key' => '',
            'app_secret' => '',
        ])->assertRedirect(route('ext.lazada.index'));

        $row = LazadaSetting::query()->first();
        $this->assertSame('live-app-secret', decrypt($row->app_secret));
        $this->assertSame('100123', (string) $row->app_key);
        $this->assertSame('vn', $row->region);
    }

    public function test_the_lazada_environment_switch_posts_the_mode_it_names(): void
    {
        $this->seedLazadaSetting(['mode' => 'live']);
        $user = $this->lazadaManager();

        $this->actingAs($user)->post(route('ext.lazada.toggle_mode'), ['mode' => 'sandbox'])
            ->assertRedirect(route('ext.lazada.index'));

        $this->assertSame('sandbox', LazadaSetting::query()->first()->mode);

        $this->actingAs($user)->post(route('ext.lazada.toggle_mode'), ['mode' => 'live'])
            ->assertRedirect(route('ext.lazada.index'));

        $this->assertSame('live', LazadaSetting::query()->first()->mode);
    }

    public function test_the_lazada_log_mode_control_saves_and_returns_to_the_panel(): void
    {
        $this->seedLazadaSetting(['api_log_mode' => 'all']);
        $user = $this->lazadaManager();

        $this->actingAs($user)
            ->post(route('ext.lazada.api_log_mode'), ['mode' => 'errors'])
            ->assertRedirect(route('ext.lazada.index', ['tab' => 'logs']));

        $this->assertSame('errors', LazadaSetting::query()->first()->api_log_mode);
    }

    public function test_the_lazada_redirect_uri_is_the_callback_and_is_not_submittable(): void
    {
        $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        $expected = preg_replace('/^http:/i', 'https:', route('lazada.callback'));
        $this->assertStringContainsString(e($expected), $html);

        $this->assertStringNotContainsString('name="redirect_uri"', $html);
        $this->assertStringNotContainsString('name="sandbox_redirect_uri"', $html);
    }

    public function test_the_lazada_settings_page_still_carries_every_oauth_control(): void
    {
        $setting = $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.e(route('ext.lazada.authorize', ['store' => $setting->id])).'"', $html);
        $this->assertStringContainsString('href="'.e(route('ext.lazada.authorize', ['store' => $setting->id, 'sandbox' => 1])).'"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.token_create')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.token_refresh')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.sandbox_token_save')).'"', $html);
        $this->assertStringContainsString('name="code"', $html);

        $this->assertSame(
            2,
            substr_count($html, '<input type="hidden" name="sandbox" value="1">'),
            'The sandbox exchange and refresh forms must each carry the hidden sandbox flag.'
        );
    }

    public function test_the_lazada_settings_page_carries_the_whole_field_census(): void
    {
        $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        foreach ([
            'env', 'region', 'app_key', 'app_secret', 'sandbox_app_key', 'sandbox_app_secret',
            'mode', 'code', 'sandbox', 'sandbox_access_token', 'expires_in_days', 'days',
            'method', 'api_path', 'auth_required', 'params_json', 'params_json_pretty',
        ] as $field) {
            $this->assertStringContainsString(
                'name="'.$field.'"',
                $html,
                "The Lazada settings page no longer carries a field named {$field}."
            );
        }

        $this->assertSame(2, substr_count($html, 'name="region"'), 'Both environment forms must post the shared region field.');

        $this->assertStringContainsString('name="map[ready_to_ship]"', $html);
        $this->assertStringContainsString('name="map[refund_paid]"', $html);
    }

    public function test_each_lazada_credential_form_carries_its_own_env_value(): void
    {
        $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        $saveAction = 'action="'.e(route('ext.lazada.save')).'"';
        preg_match_all('#<form[^>]*'.preg_quote($saveAction, '#').'.*?</form>#s', $html, $matches);
        $this->assertCount(2, $matches[0], 'Expected exactly two Lazada credential-save forms.');

        [$liveForm, $sandboxForm] = $matches[0];

        $this->assertStringContainsString('name="app_key"', $liveForm);
        $this->assertStringNotContainsString('name="sandbox_app_key"', $liveForm);
        $this->assertStringContainsString('name="env" value="live"', $liveForm);
        $this->assertStringNotContainsString('value="sandbox"', $liveForm,
            'The production credential form carried the sandbox env value.');

        $this->assertStringContainsString('name="sandbox_app_key"', $sandboxForm);
        $this->assertStringContainsString('name="env" value="sandbox"', $sandboxForm);
        $this->assertStringNotContainsString('value="live"', $sandboxForm,
            'The sandbox credential form carried the live env value, which would overwrite production on save.');
    }

    public function test_stored_lazada_secrets_never_reach_the_page_source(): void
    {
        $this->seedLazadaSetting([
            'sandbox_app_secret' => encrypt('sandbox-app-secret'),
            'sandbox_access_token' => encrypt('sandbox-access-token'),
        ]);

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        foreach ([
            'live-app-secret', 'live-access-token',
            'sandbox-app-secret', 'sandbox-access-token',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $this->assertStringContainsString('data-credential-mask="1"', $html);
    }

    public function test_the_lazada_api_log_tab_renders_a_recorded_call(): void
    {
        $this->seedLazadaSetting();

        LazadaApiLog::create([
            'method' => 'GET',
            'api_path' => '/seller/get',
            'auth_required' => true,
            'request_params' => ['limit' => 10],
            'response_status' => 200,
            'ok' => true,
            'response_body' => ['data' => ['name' => 'Test & Co']],
        ]);

        $this->actingAs($this->lazadaManager())->get(route('ext.lazada.index'))
            ->assertOk()
            ->assertSee('/seller/get')
            ->assertSee('action="'.e(route('ext.lazada.clear_api_logs')).'"', false);
    }

    public function test_the_lazada_listings_page_keeps_its_sort_contract(): void
    {
        $this->seedLazadaSetting();
        $pid = $this->seedCatalogProduct('Sortable Strings', 'SORT-1');
        LazadaProduct::create(['product_id' => $pid, 'lazada_item_id' => '551122']);
        $user = $this->lazadaManager();

        $html = $this->actingAs($user)
            ->get(route('ext.lazada.products.index', ['sort' => 'quantity', 'dir' => 'asc']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('sort=quantity&amp;dir=desc', $html);
        $this->assertStringNotContainsString('name="sort" value="quantity"', $html);
    }

    private function tierUser(string $label, array $permissions): User
    {
        $group = UserGroup::create(['name' => $label.' '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function tiktokViewer(): User { return $this->tierUser('TikTok Viewer', ['view_tiktok/settings', 'view_tiktok/product_group', 'view_tiktok/dashboard']); }
    private function tiktokManager(): User { return $this->tierUser('TikTok Manager', ['manage_tiktok/settings', 'manage_tiktok/product_group', 'manage_tiktok/dashboard']); }
    private function ventaCartViewer(): User { return $this->tierUser('VentaCart Viewer', ['view_ventacart/settings', 'view_ventacart/product_group', 'view_ventacart/dashboard']); }
    private function ventaCartManager(): User { return $this->tierUser('VentaCart Manager', ['manage_ventacart/settings', 'manage_ventacart/product_group', 'manage_ventacart/review', 'manage_ventacart/dashboard']); }
    private function opencartViewer(): User { return $this->tierUser('OpenCart Viewer', ['view_opencart/settings', 'view_opencart/product_group', 'view_opencart/dashboard']); }
    private function opencartManager(): User { return $this->tierUser('OpenCart Manager', ['manage_opencart/settings', 'manage_opencart/product_group', 'manage_opencart/dashboard']); }

    private function seedTiktokSetting(array $overrides = []): TikTokSetting
    {
        $setting = new TikTokSetting();
        $setting->forceFill(array_merge([
            'mode' => 'live',
            'app_key' => '6h1tv0kbcdefg',
            'app_secret' => encrypt('live-app-secret'),
            'access_token' => encrypt('live-access-token'),
            'shop_id' => '7495',
            'shop_cipher' => 'TTP_cipher_abc',
            'shop_name' => 'Acme TikTok',
            'region' => 'ph',
            'api_log_mode' => 'all',
        ], $overrides));
        $setting->save();

        return $setting;
    }

    private function seedVentaStores(): array
    {
        $one = VentaCartSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => 'https://one.ventacart.test',
            'api_token' => 'ventacart-token-one',
            'enabled' => true,
            'api_log_mode' => 'all',
            'sync_last_days' => 30,
            'warehouse_id' => 4,
            'brand_color' => '#059669',
        ]);

        $two = VentaCartSetting::create([
            'store_name' => 'Switch Bazaar',
            'base_url' => 'https://two.ventacart.test',
            'api_token' => 'ventacart-token-two',
            'enabled' => true,
            'api_log_mode' => 'off',
            'sync_last_days' => 14,
            'warehouse_id' => 9,
            'brand_color' => '#123456',
        ]);

        return [$one->id, $two->id];
    }

    private function seedOpencartStores(): array
    {
        $one = OpenCartSetting::create([
            'store_name' => 'Parts Yard',
            'base_url' => 'https://one.opencart.test',
            'api_key' => 'oc-key-one',
            'enabled' => true,
            'sync_last_days' => 7,
            'brand_color' => '#16a34a',
            'review_auto_approve' => true,
        ]);

        $two = OpenCartSetting::create([
            'store_name' => 'Cable Loft',
            'base_url' => 'https://two.opencart.test',
            'api_key' => 'oc-key-two',
            'enabled' => true,
            'sync_last_days' => 21,
            'brand_color' => '#654321',
            'review_auto_approve' => false,
        ]);

        return [$one->id, $two->id];
    }

    private function seedCatalogProduct(string $name, string $sku): int
    {
        $prefix = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $id = DB::table($prefix.'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 6,
            'price' => 249.0000, 'status' => 1, 'image' => null,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($prefix.'product_description')->insert([
            'product_id' => $id, 'language_id' => $langId, 'name' => $name,
            'description' => '', 'tag' => '', 'meta_title' => $name,
            'meta_description' => '', 'meta_keyword' => '',
        ]);

        return $id;
    }

    private function tiktokInteriorUrls(): array
    {
        return [
            'settings'       => route('ext.tiktok.index'),
            'categories'     => route('ext.tiktok.categories.index'),
            'product-groups' => route('ext.tiktok.product-groups.index'),
        ];
    }

    private function ventaCartInteriorUrls(int $store, int $group): array
    {
        return [
            'settings'          => route('ext.ventacart.settings.show', ['store' => $store]),
            'product-groups'    => route('ext.ventacart.product-groups.index', $store),
            'group-form'        => route('ext.ventacart.product-groups.edit', [$store, $group]),
            'group-products'    => route('ext.ventacart.product-groups.products', [$store, $group]),
        ];
    }

    private function opencartInteriorUrls(int $store, int $group): array
    {
        return [
            'settings'          => route('ext.opencart.settings.show', ['store' => $store]),
            'product-groups'    => route('ext.opencart.product-groups.index', $store),
            'group-form'        => route('ext.opencart.product-groups.edit', [$store, $group]),
            'group-products'    => route('ext.opencart.product-groups.products', [$store, $group]),
        ];
    }

    private function pageContent(string $html, string $label): string
    {
        preg_match('/<main\b[^>]*class="x-content[^"]*"[^>]*>(.*?)<\/main>/s', $html, $main);
        $inside = $main[1] ?? '';
        $this->assertNotEmpty($inside, "No content region rendered for {$label}.");

        $marker = '<div id="js-flash-container"></div>';
        $at = strpos($inside, $marker);
        $this->assertNotFalse($at, "The shared page contract did not render on {$label}.");

        return substr($inside, $at + strlen($marker));
    }

    private function assertBoundaryDropped(string $html, string $label): void
    {
        $this->assertStringNotContainsString(
            'class="x-content x-legacy"',
            $html,
            "{$label} is still inside the .x-legacy content boundary."
        );
        $this->assertStringContainsString(
            'class="x-content min-w-0 flex-1',
            $html,
            "{$label} did not render the shared content region."
        );
    }

    public function test_every_tiktok_interior_renders_for_both_tiers(): void
    {
        $this->seedTiktokSetting();

        foreach ([$this->tiktokManager(), $this->tiktokViewer()] as $user) {
            foreach ($this->tiktokInteriorUrls() as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_no_tiktok_interior_still_carries_the_legacy_boundary(): void
    {
        $this->seedTiktokSetting();
        $user = $this->tiktokManager();

        foreach ($this->tiktokInteriorUrls() as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertBoundaryDropped($html, "TikTok {$label}");
        }
    }

    public function test_tiktok_interiors_render_their_seeded_records(): void
    {
        $this->seedTiktokSetting();

        TikTokCategory::create([
            'id' => '600123', 'parent_id' => '600001', 'name' => 'Guitar Pedals',
            'is_leaf' => true, 'permission_statuses' => ['AVAILABLE', 'INVITE_ONLY'],
        ]);

        $group = TikTokProductGroup::create([
            'name' => 'Pedal Batch', 'tiktok_category_id' => '600123',
            'markup_percent' => 7.5, 'markup_fixed' => 250,
        ]);

        TikTokApiLog::create([
            'method' => 'GET', 'api_path' => '/authorization/202309/shops',
            'auth_required' => true, 'request_params' => ['a' => 1],
            'response_status' => 200, 'ok' => true, 'response_body' => ['data' => []],
        ]);

        $user = $this->tiktokManager();

        $categories = $this->actingAs($user)->get(route('ext.tiktok.categories.index'))->assertOk();
        $categories->assertSee('Guitar Pedals');
        $categories->assertSee('600123');
        $categories->assertSee('AVAILABLE, INVITE_ONLY');

        $groups = $this->actingAs($user)->get(route('ext.tiktok.product-groups.index'))->assertOk();
        $groups->assertSee('Pedal Batch');
        $groups->assertSee('Guitar Pedals');
        $groups->assertSee('7.5% of price');

        $settings = $this->actingAs($user)->get(route('ext.tiktok.index'))->assertOk();
        $settings->assertSee('/authorization/202309/shops');
        $settings->assertSee('TTP_cipher_abc');

        $this->assertGreaterThan(0, $group->id);
    }

    public function test_tiktok_channel_sourced_names_are_escaped(): void
    {
        $this->seedTiktokSetting();

        $payload = '<img src=x onerror=alert(1)>';

        TikTokCategory::create([
            'id' => '600999', 'parent_id' => null, 'name' => $payload,
            'is_leaf' => false, 'permission_statuses' => [$payload],
        ]);
        TikTokProductGroup::create(['name' => $payload, 'tiktok_category_id' => '600999']);

        $user = $this->tiktokManager();

        foreach ([
            route('ext.tiktok.categories.index'),
            route('ext.tiktok.product-groups.index'),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=', $html, "Unescaped output on {$url}");
            $this->assertStringContainsString('&lt;img src=x onerror=', $html);
        }
    }

    public function test_an_operator_with_no_tiktok_permission_is_refused_every_tiktok_interior(): void
    {
        $this->seedTiktokSetting();
        $user = $this->outsider();

        foreach ($this->tiktokInteriorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), $label);
        }
    }

    public function test_guests_are_redirected_away_from_every_tiktok_interior(): void
    {
        $this->seedTiktokSetting();

        foreach ($this->tiktokInteriorUrls() as $label => $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_tiktok_read_tier_is_not_offered_write_controls(): void
    {
        $this->seedTiktokSetting();
        TikTokProductGroup::create(['name' => 'Pedal Batch', 'tiktok_category_id' => '600123']);
        $user = $this->tiktokViewer();

        $categories = $this->actingAs($user)->get(route('ext.tiktok.categories.index'))->assertOk();
        $categories->assertDontSee(route('ext.tiktok.categories.sync'), false);

        $groups = $this->actingAs($user)->get(route('ext.tiktok.product-groups.index'))->assertOk();
        $groups->assertDontSee(route('ext.tiktok.product-groups.create'), false);
        $groups->assertDontSee('Delete group');

        $settings = $this->actingAs($user)->get(route('ext.tiktok.index'))->assertOk();

        foreach ([
            'ext.tiktok.toggle_mode', 'ext.tiktok.token_get',
            'ext.tiktok.token_refresh', 'ext.tiktok.shops', 'ext.tiktok.authorize',
            'ext.tiktok.explorer_run', 'ext.tiktok.packs_run',
            'ext.tiktok.api_log_mode', 'ext.tiktok.clear_api_logs', 'ext.tiktok.purge_raw',
        ] as $name) {
            $settings->assertDontSee(route($name), false);
        }

        $settings->assertDontSee('Save production settings');
        $settings->assertDontSee('Save sandbox settings');
        $settings->assertDontSee('Save order mapping');

        $html = $settings->getContent();
        $this->assertStringContainsString('name="app_key"', $html, 'The read tier lost the credential field entirely.');
        foreach (['app_key', 'app_secret', 'sandbox_app_key', 'sandbox_app_secret'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
    }

    public function test_the_tiktok_manage_tier_is_offered_those_same_controls(): void
    {
        $this->seedTiktokSetting();
        TikTokApiLog::create([
            'method' => 'GET', 'api_path' => '/authorization/202309/shops',
            'auth_required' => true, 'request_params' => [],
            'response_status' => 200, 'ok' => true, 'response_body' => [],
        ]);
        $user = $this->tiktokManager();

        $this->actingAs($user)->get(route('ext.tiktok.categories.index'))
            ->assertOk()->assertSee(route('ext.tiktok.categories.sync'), false);

        $this->actingAs($user)->get(route('ext.tiktok.product-groups.index'))
            ->assertOk()->assertSee(route('ext.tiktok.product-groups.create'), false);

        $settings = $this->actingAs($user)->get(route('ext.tiktok.index'))->assertOk();
        foreach ([
            'ext.tiktok.toggle_mode', 'ext.tiktok.save', 'ext.tiktok.token_get',
            'ext.tiktok.token_refresh', 'ext.tiktok.authorize',
            'ext.tiktok.explorer_run', 'ext.tiktok.packs_run',
            'ext.tiktok.api_log_mode', 'ext.tiktok.clear_api_logs', 'ext.tiktok.purge_raw',
            'ext.tiktok.order_status_map',
        ] as $name) {
            $settings->assertSee(route($name), false);
        }
    }

    public function test_the_tiktok_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        $this->seedTiktokSetting();
        $user = $this->tiktokViewer();

        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.categories.sync')), 'categories.sync');
        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.save'), ['env' => 'live']), 'save');
        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.toggle_mode'), ['mode' => 'sandbox']), 'toggle_mode');
        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.shops')), 'shops');
        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.token_refresh')), 'token_refresh');
        $this->assertDenied($this->actingAs($user)->post(route('ext.tiktok.purge_raw'), ['days' => 30]), 'purge_raw');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.tiktok.clear_api_logs')), 'clear_api_logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.tiktok.product-groups.create')), 'product-groups.create');
    }

    public function test_the_tiktok_settings_page_carries_the_whole_field_census(): void
    {
        $this->seedTiktokSetting();
        $html = $this->actingAs($this->tiktokManager())->get(route('ext.tiktok.index'))->assertOk()->getContent();

        foreach ([
            'name="env" value="live"',
            'name="env" value="sandbox"',
            'name="app_key"',
            'name="app_secret"',
            'name="sandbox_app_key"',
            'name="sandbox_app_secret"',
            'name="mode"',
            'name="code"',
            'name="days"',
            'name="pack"',
            'name="method"',
            'name="api_path"',
            'name="auth_required"',
            'name="params_json_pretty"',
            'name="params_json"',
            'name="map[UNPAID]"',
            'name="map[COMPLETED]"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "The settings page lost {$needle}.");
        }

        $this->assertSame(1, substr_count($html, 'name="env" value="live"'));
        $this->assertSame(1, substr_count($html, 'name="env" value="sandbox"'));
    }

    public function test_the_tiktok_settings_page_still_carries_every_oauth_control(): void
    {
        $setting = $this->seedTiktokSetting();
        $html = $this->actingAs($this->tiktokManager())->get(route('ext.tiktok.index'))->assertOk()->getContent();

        $authorize = route('ext.tiktok.authorize', ['store' => $setting->id]);

        $this->assertSame(2, substr_count($html, 'href="'.e($authorize).'"'));
        $this->assertStringNotContainsString($authorize.'?sandbox', $html);
        $this->assertStringNotContainsString(e($authorize).'?sandbox', $html);
        $this->assertStringContainsString('rel="noopener"', $html);

        $this->assertStringNotContainsString('name="sandbox"', $html);

        $this->assertStringContainsString(route('ext.tiktok.token_get'), $html);
        $this->assertStringContainsString(route('ext.tiktok.token_refresh'), $html);
        $this->assertStringContainsString('/setup/shop', $html);
    }

    public function test_the_tiktok_redirect_uri_is_shown_but_not_submittable(): void
    {
        $this->seedTiktokSetting();
        $html = $this->actingAs($this->tiktokManager())->get(route('ext.tiktok.index'))->assertOk()->getContent();

        $redirect = preg_replace('/^http:/i', 'https:', route('ext.tiktok.callback'));

        $this->assertStringContainsString(e($redirect), $html);
        $this->assertSame(2, substr_count($html, 'value="'.e($redirect).'"'));

        preg_match_all('/<input[^>]*value="'.preg_quote(e($redirect), '/').'"[^>]*>/', $html, $matches);
        $this->assertCount(2, $matches[0]);
        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('readonly', $tag);
            $this->assertStringNotContainsString('name=', $tag);
        }
    }

    public function test_the_tiktok_settings_save_round_trips_both_environments(): void
    {
        $this->seedTiktokSetting();
        $user = $this->tiktokManager();

        $this->actingAs($user)->post(route('ext.tiktok.save'), [
            'env' => 'live',
            'app_key' => 'new-live-key',
            'app_secret' => 'new-live-secret',
        ])->assertRedirect(route('ext.tiktok.index'));

        $setting = TikTokSetting::query()->first();
        $this->assertSame('live', $setting->mode);
        $this->assertSame('new-live-key', $setting->app_key);
        $this->assertSame('new-live-secret', decrypt($setting->app_secret));

        $this->actingAs($user)->post(route('ext.tiktok.save'), [
            'env' => 'sandbox',
            'sandbox_app_key' => 'new-sandbox-key',
            'sandbox_app_secret' => 'new-sandbox-secret',
        ])->assertRedirect(route('ext.tiktok.index'));

        $setting->refresh();
        $this->assertSame('sandbox', $setting->mode);
        $this->assertSame('new-sandbox-key', $setting->sandbox_app_key);
        $this->assertSame('new-sandbox-secret', decrypt($setting->sandbox_app_secret));
        $this->assertSame('new-live-key', $setting->app_key);
    }

    public function test_a_tiktok_save_that_omits_the_masked_secret_keeps_it(): void
    {
        $this->seedTiktokSetting();

        $this->actingAs($this->tiktokManager())->post(route('ext.tiktok.save'), [
            'env' => 'live',
            'app_key' => 'still-here',
            'app_secret' => '',
        ])->assertRedirect();

        $setting = TikTokSetting::query()->first();
        $this->assertSame('still-here', $setting->app_key);
        $this->assertSame('live-app-secret', decrypt($setting->app_secret));
    }

    public function test_a_tiktok_production_save_preserves_the_stored_region(): void
    {
        $this->seedTiktokSetting(['region' => 'ph']);

        $this->actingAs($this->tiktokManager())->post(route('ext.tiktok.save'), [
            'env' => 'live',
            'app_key' => 'still-here',
        ])->assertRedirect();

        $this->assertSame('ph', TikTokSetting::query()->first()->region);
    }

    public function test_stored_tiktok_secrets_never_reach_the_page_source(): void
    {
        $this->seedTiktokSetting(['sandbox_app_secret' => encrypt('sandbox-app-secret')]);

        foreach ([$this->tiktokManager(), $this->tiktokViewer()] as $user) {
            $html = $this->actingAs($user)->get(route('ext.tiktok.index'))->assertOk()->getContent();

            $this->assertStringNotContainsString('live-app-secret', $html);
            $this->assertStringNotContainsString('sandbox-app-secret', $html);
            $this->assertStringNotContainsString('live-access-token', $html);
            $this->assertStringContainsString('••••••••', $html, 'The mask that says a secret IS stored is gone.');
        }
    }

    public function test_the_tiktok_log_mode_control_saves_and_returns_to_the_panel(): void
    {
        $this->seedTiktokSetting(['api_log_mode' => 'all']);

        $this->actingAs($this->tiktokManager())
            ->post(route('ext.tiktok.api_log_mode'), ['mode' => 'off'])
            ->assertRedirect(route('ext.tiktok.index', ['tab' => 'logs']));

        $this->assertSame('off', TikTokSetting::query()->first()->api_log_mode);

        $this->actingAs($this->tiktokManager())
            ->post(route('ext.tiktok.api_log_mode'), ['mode' => 'all'])
            ->assertRedirect(route('ext.tiktok.index', ['tab' => 'logs']));

        $this->assertSame('all', TikTokSetting::query()->first()->api_log_mode);
    }

    public function test_every_ventacart_interior_renders_for_both_tiers(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);

        foreach ([$this->ventaCartManager(), $this->ventaCartViewer()] as $user) {
            foreach ($this->ventaCartInteriorUrls($one, $group->id) as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_no_ventacart_interior_still_carries_the_legacy_boundary(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaCartManager();

        foreach ($this->ventaCartInteriorUrls($one, $group->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertBoundaryDropped($html, "VentaCart {$label}");
        }
    }

    public function test_every_ventacart_self_link_carries_the_store_it_arrived_on(): void
    {
        [$one, $two] = $this->seedVentaStores();
        $groupTwo = VentaCartProductGroup::create(['ventacart_setting_id' => $two, 'name' => 'Switches']);
        $user = $this->ventaCartManager();

        foreach ($this->ventaCartInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "VentaCart {$label}");

            $this->assertStringNotContainsString('/channels/ventacart/'.$one.'/', $content,
                "VentaCart {$label} for store {$two} leaked a link to store {$one}.");
            $this->assertStringNotContainsString('name="store_id" value="'.$one.'"', $content,
                "VentaCart {$label} for store {$two} posts store {$one}'s id.");

            if ($label === 'settings') {
                $this->assertStringContainsString('name="store_id" value="'.$two.'"', $content,
                    'VentaCart settings does not post the store it was asked for.');
                $this->assertStringContainsString('data-store-id="'.$two.'"', $content,
                    'VentaCart settings does not scope its AJAX calls to the store it was asked for.');
            } else {
                $this->assertStringContainsString('/channels/ventacart/'.$two.'/', $content,
                    "VentaCart {$label} emitted no link scoped to the store it was asked for.");
            }
        }
    }

    public function test_every_ventacart_interior_names_its_store_in_the_content(): void
    {
        [, $two] = $this->seedVentaStores();
        $groupTwo = VentaCartProductGroup::create(['ventacart_setting_id' => $two, 'name' => 'Switches']);
        $user = $this->ventaCartManager();

        foreach ($this->ventaCartInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "VentaCart {$label}");

            $this->assertStringContainsString('Switch Bazaar', $content,
                "VentaCart {$label} does not name its store anywhere in the page body.");
            $this->assertStringNotContainsString('Gear Depot', $content,
                "VentaCart {$label} names the wrong store.");
        }
    }

    public function test_an_operator_with_no_ventacart_permission_is_refused_every_ventacart_interior(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->outsider();

        foreach ($this->ventaCartInteriorUrls($one, $group->id) as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), $label);
        }
    }

    public function test_guests_are_redirected_away_from_every_ventacart_interior(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);

        foreach ($this->ventaCartInteriorUrls($one, $group->id) as $label => $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_ventacart_read_tier_is_not_offered_write_controls(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaCartViewer();

        $groups = $this->actingAs($user)->get(route('ext.ventacart.product-groups.index', $one))->assertOk();
        $groups->assertDontSee(route('ext.ventacart.product-groups.create', $one), false);
        $groups->assertDontSee('Delete group');

        $form = $this->actingAs($user)->get(route('ext.ventacart.product-groups.edit', [$one, $group->id]))->assertOk();
        $form->assertDontSee('Save product group');
        $this->assertMatchesRegularExpression('/name="name"[^>]*\bdisabled\b/', $form->getContent());

        $products = $this->actingAs($user)->get(route('ext.ventacart.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertDontSee(route('ext.ventacart.product-groups.push', [$one, $group->id]), false);
        $products->assertDontSee(route('ext.ventacart.product-groups.addProducts', [$one, $group->id]), false);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['data' => [
                ['id' => 901, 'sku' => 'ORPHAN-1', 'name' => 'Made in VentaCart directly'],
                ['id' => 902, 'sku' => 'ORPHAN-2', 'name' => 'Left behind by a removed link'],
            ]], 200),
        ]);

        $this->actingAs($user)->get(route('ext.ventacart.product-groups.orphans', [$one, $group->id]))
            ->assertOk()
            ->assertSee('ORPHAN-1')
            ->assertSee('Made in VentaCart directly')
            ->assertDontSee('Adopt')
            ->assertDontSee(route('ext.ventacart.product-groups.check', [$one, $group->id]), false);

        $settings = $this->actingAs($user)->get(route('ext.ventacart.settings.show', ['store' => $one]))->assertOk();
        foreach ([
            route('ext.ventacart.test'),
            route('ext.ventacart.fetch_statuses'),
            route('ext.ventacart.save_status_map'),
            route('ext.ventacart.api_log_mode', $one),
            route('ext.ventacart.clear_api_logs', $one),
        ] as $url) {
            $settings->assertDontSee($url, false);
        }

        $settings->assertDontSee('Save connection');
        $settings->assertDontSee('Test connection');
        $settings->assertDontSee('Delete store');
        $settings->assertDontSee('action="'.e(route('ext.ventacart.destroy', $one)).'"', false);

        $html = $settings->getContent();
        foreach (['store_name', 'base_url', 'api_token', 'warehouse_id', 'sync_last_days', 'sync_orders_from', 'enabled'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
        $this->assertStringNotContainsString('ventacart-token-one', $html,
            'The read tier was sent the store API token.');
    }

    public function test_the_ventacart_manage_tier_is_offered_those_same_controls(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);
        VentaCartApiLog::create([
            'ventacart_setting_id' => $one, 'method' => 'GET', 'endpoint' => '/api/orders',
            'status_code' => 200, 'response_time_ms' => 12, 'ok' => true,
            'request_body' => null, 'response_body' => [],
        ]);
        $user = $this->ventaCartManager();

        $this->actingAs($user)->get(route('ext.ventacart.product-groups.index', $one))
            ->assertOk()->assertSee(route('ext.ventacart.product-groups.create', $one), false);

        $this->actingAs($user)->get(route('ext.ventacart.product-groups.edit', [$one, $group->id]))
            ->assertOk()->assertSee('Save product group');

        $products = $this->actingAs($user)->get(route('ext.ventacart.product-groups.products', [$one, $group->id]))->assertOk();
        foreach ([
            route('ext.ventacart.product-groups.push', [$one, $group->id]),
            route('ext.ventacart.product-groups.check', [$one, $group->id]),
            route('ext.ventacart.product-groups.mass-remove', [$one, $group->id]),
            route('ext.ventacart.product-groups.addProducts', [$one, $group->id]),
        ] as $url) {
            $products->assertSee($url, false);
        }

        $settings = $this->actingAs($user)->get(route('ext.ventacart.settings.show', ['store' => $one]))->assertOk();
        foreach ([
            route('ext.ventacart.save'),
            route('ext.ventacart.test'),
            route('ext.ventacart.fetch_statuses'),
            route('ext.ventacart.save_status_map'),
            route('ext.ventacart.api_log_mode', $one),
            route('ext.ventacart.clear_api_logs', $one),
        ] as $url) {
            $settings->assertSee($url, false);
        }
    }

    public function test_the_ventacart_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaCartViewer();

        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.save'), [
            'store_id' => $one, 'base_url' => 'https://x.test', 'api_token' => 'x',
        ]), 'save');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.ventacart.destroy', $one)), 'destroy');
        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.test'), ['store_id' => $one]), 'test');
        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.fetch_statuses'), ['store_id' => $one]), 'fetch_statuses');
        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.save_status_map'), ['store_id' => $one]), 'save_status_map');
        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.api_log_mode', $one)), 'api_log_mode');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.ventacart.clear_api_logs', $one)), 'clear_api_logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.ventacart.product-groups.create', $one)), 'product-groups.create');
        $this->assertDenied($this->actingAs($user)->post(route('ext.ventacart.product-groups.push', [$one, $group->id])), 'product-groups.push');
    }

    public function test_a_ventacart_save_for_one_store_leaves_the_other_store_untouched(): void
    {
        [$one, $two] = $this->seedVentaStores();

        $before = VentaCartSetting::findOrFail($two);
        $beforeTwo = [
            'store_name' => $before->store_name,
            'base_url' => $before->base_url,
            'api_token' => $before->api_token,
            'enabled' => (bool) $before->enabled,
            'warehouse_id' => $before->warehouse_id,
            'sync_last_days' => $before->sync_last_days,
            'brand_color' => $before->brand_color,
        ];

        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), [
            'store_id' => $one,
            'store_name' => 'Gear Depot Renamed',
            'brand_color' => '#aabbcc',
            'base_url' => 'https://renamed.ventacart.test',
            'api_token' => 'ventacart-token-one-rotated',
            'warehouse_id' => 11,
            'sync_last_days' => 45,
            'sync_orders_from' => '2026-01-15',
            'enabled' => '1',
        ])->assertRedirect(route('ext.ventacart.settings.show', ['store' => $one]));

        $saved = VentaCartSetting::findOrFail($one);
        $this->assertSame('Gear Depot Renamed', $saved->store_name);
        $this->assertSame('#aabbcc', $saved->brand_color);
        $this->assertSame('https://renamed.ventacart.test', $saved->base_url);
        $this->assertSame('ventacart-token-one-rotated', $saved->api_token);
        $this->assertSame(11, (int) $saved->warehouse_id);
        $this->assertSame(45, (int) $saved->sync_last_days);
        $this->assertSame('2026-01-15', $saved->sync_orders_from->format('Y-m-d'));
        $this->assertTrue((bool) $saved->enabled);

        $after = VentaCartSetting::findOrFail($two);
        $this->assertSame($beforeTwo['store_name'], $after->store_name);
        $this->assertSame($beforeTwo['base_url'], $after->base_url);
        $this->assertSame($beforeTwo['api_token'], $after->api_token);
        $this->assertSame($beforeTwo['enabled'], (bool) $after->enabled);
        $this->assertSame($beforeTwo['warehouse_id'], $after->warehouse_id);
        $this->assertSame($beforeTwo['sync_last_days'], $after->sync_last_days);
        $this->assertSame($beforeTwo['brand_color'], $after->brand_color);
    }

    public function test_a_ventacart_save_with_a_blank_token_keeps_the_stored_one(): void
    {
        [$one] = $this->seedVentaStores();

        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), [
            'store_id' => $one,
            'store_name' => 'Gear Depot Renamed',
            'base_url' => 'https://one.ventacart.test',
            'api_token' => '',
        ])->assertRedirect(route('ext.ventacart.settings.show', ['store' => $one]));

        $saved = VentaCartSetting::findOrFail($one);
        $this->assertSame('Gear Depot Renamed', $saved->store_name, 'The rest of the form still saved.');
        $this->assertSame('ventacart-token-one', $saved->api_token, 'The stored token survived a blank submission.');
    }

    public function test_a_ventacart_save_with_a_new_token_replaces_the_stored_one(): void
    {
        [$one] = $this->seedVentaStores();

        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), [
            'store_id' => $one,
            'base_url' => 'https://one.ventacart.test',
            'api_token' => 'ventacart-token-one-replaced',
        ])->assertRedirect(route('ext.ventacart.settings.show', ['store' => $one]));

        $this->assertSame('ventacart-token-one-replaced', VentaCartSetting::findOrFail($one)->api_token);
    }

    public function test_a_new_ventacart_store_still_requires_the_api_token(): void
    {
        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), [
            'base_url' => 'https://new.ventacart.test',
        ])->assertSessionHasErrors('api_token');

        $this->assertSame(0, VentaCartSetting::query()->count());
    }

    public function test_the_ventacart_api_token_never_reaches_the_page_source_for_either_tier(): void
    {
        [$one] = $this->seedVentaStores();

        foreach ([$this->ventaCartManager(), $this->ventaCartViewer()] as $user) {
            $html = $this->actingAs($user)->get(route('ext.ventacart.settings.show', ['store' => $one]))
                ->assertOk()->getContent();

            $this->assertStringNotContainsString('ventacart-token-one', $html,
                'The stored API token reached the page source.');
        }
    }

    public function test_the_ventacart_enabled_switch_can_actually_be_turned_off(): void
    {
        [$one] = $this->seedVentaStores();

        $html = $this->actingAs($this->ventaCartManager())
            ->get(route('ext.ventacart.settings.show', ['store' => $one]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="enabled" value="0"', $html,
            'A hidden companion field would make $request->has(\'enabled\') always true.');

        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), [
            'store_id' => $one,
            'base_url' => 'https://one.ventacart.test',
            'api_token' => 'ventacart-token-one',
        ])->assertRedirect();

        $this->assertFalse((bool) VentaCartSetting::findOrFail($one)->enabled);
    }

    public function test_the_ventacart_status_map_save_is_scoped_to_its_own_store(): void
    {
        [$one, $two] = $this->seedVentaStores();

        VentaCartOrderStatusMap::create([
            'ventacart_setting_id' => $two, 'ventacart_status_id' => 3,
            'ventacart_status_name' => 'Shipped', 'order_status_id' => 3,
        ]);

        $this->actingAs($this->ventaCartManager())->postJson(route('ext.ventacart.save_status_map'), [
            'store_id' => $one,
            'mappings' => [
                ['ventacart_status_id' => 3, 'ventacart_status_name' => 'Shipped', 'order_status_id' => 5],
            ],
        ])->assertOk();

        $this->assertSame(5, (int) VentaCartOrderStatusMap::where('ventacart_setting_id', $one)
            ->where('ventacart_status_id', 3)->value('order_status_id'));
        $this->assertSame(3, (int) VentaCartOrderStatusMap::where('ventacart_setting_id', $two)
            ->where('ventacart_status_id', 3)->value('order_status_id'));
    }

    public function test_ventacart_interiors_render_their_seeded_records(): void
    {
        [$one] = $this->seedVentaStores();
        $productId = $this->seedCatalogProduct('Overdrive Pedal', 'OD-808');

        $group = VentaCartProductGroup::create([
            'ventacart_setting_id' => $one, 'name' => 'Pedal Batch',
            'markup_percent' => 5, 'markup_fixed' => 100,
        ]);
        DB::table('ventacart_product_group_products')->insert([
            'ventacart_product_group_id' => $group->id, 'product_id' => $productId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        VentaCartOrderStatusMap::create([
            'ventacart_setting_id' => $one, 'ventacart_status_id' => 7,
            'ventacart_status_name' => 'Awaiting pickup', 'order_status_id' => 0,
        ]);
        VentaCartApiLog::create([
            'ventacart_setting_id' => $one, 'method' => 'GET', 'endpoint' => '/api/orders',
            'status_code' => 200, 'response_time_ms' => 84, 'ok' => true,
            'request_body' => null, 'response_body' => ['data' => []],
        ]);
        VentaCartSyncLog::create([
            'ventacart_setting_id' => $one, 'entity_type' => 'orders', 'direction' => 'pull',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'completed_at' => now(),
            'records_processed' => 12, 'records_updated' => 4, 'records_failed' => 0,
        ]);

        $user = $this->ventaCartManager();

        $groups = $this->actingAs($user)->get(route('ext.ventacart.product-groups.index', $one))->assertOk();
        $groups->assertSee('Pedal Batch');
        $groups->assertSee('5% of price');

        $products = $this->actingAs($user)->get(route('ext.ventacart.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertSee('Overdrive Pedal');
        $products->assertSee('OD-808');

        $settings = $this->actingAs($user)->get(route('ext.ventacart.settings.show', ['store' => $one]))->assertOk();
        $settings->assertSee('Awaiting pickup');
        $settings->assertSee('/api/orders');
        $settings->assertSee('Sync Orders');
        $settings->assertSee('Push Stock');
        $settings->assertSee('Push Price');
        $settings->assertSee('Push Reviews');

        $settings->assertDontSee('schedule:run');
    }

    public function test_ventacart_channel_sourced_names_are_escaped(): void
    {
        [$one] = $this->seedVentaStores();
        $payload = '<img src=x onerror=alert(1)>';

        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $one, 'name' => $payload]);
        VentaCartOrderStatusMap::create([
            'ventacart_setting_id' => $one, 'ventacart_status_id' => 9,
            'ventacart_status_name' => $payload, 'order_status_id' => 0,
        ]);
        VentaCartApiLog::create([
            'ventacart_setting_id' => $one, 'method' => 'GET', 'endpoint' => $payload,
            'status_code' => 500, 'response_time_ms' => 3, 'ok' => false,
            'request_body' => null, 'response_body' => ['error' => $payload],
        ]);

        $user = $this->ventaCartManager();

        foreach ([
            route('ext.ventacart.product-groups.index', $one),
            route('ext.ventacart.product-groups.edit', [$one, $group->id]),
            route('ext.ventacart.settings.show', ['store' => $one]),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=', $html, "Unescaped output on {$url}");
        }
    }

    public function test_every_opencart_interior_renders_for_both_tiers(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);

        foreach ([$this->opencartManager(), $this->opencartViewer()] as $user) {
            foreach ($this->opencartInteriorUrls($one, $group->id) as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_no_opencart_interior_still_carries_the_legacy_boundary(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);
        $user = $this->opencartManager();

        foreach ($this->opencartInteriorUrls($one, $group->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertBoundaryDropped($html, "OpenCart {$label}");
        }
    }

    public function test_every_opencart_self_link_carries_the_store_it_arrived_on(): void
    {
        [$one, $two] = $this->seedOpencartStores();
        $groupTwo = OpenCartProductGroup::create(['opencart_setting_id' => $two, 'name' => 'Looms']);
        $user = $this->opencartManager();

        foreach ($this->opencartInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "OpenCart {$label}");

            $this->assertStringNotContainsString('/channels/opencart/'.$one.'/', $content,
                "OpenCart {$label} for store {$two} leaked a link to store {$one}.");
            $this->assertStringNotContainsString('name="store_id" value="'.$one.'"', $content,
                "OpenCart {$label} for store {$two} posts store {$one}'s id.");

            if ($label === 'settings') {
                $this->assertStringContainsString('name="store_id" value="'.$two.'"', $content,
                    'OpenCart settings does not post the store it was asked for.');
                $this->assertStringContainsString('data-store-id="'.$two.'"', $content,
                    'OpenCart settings does not scope its AJAX calls to the store it was asked for.');
                preg_match_all('/name="jobs\[(\d+)\]/', $content, $rendered);

                $this->assertNotEmpty($rendered[1],
                    'OpenCart settings rendered no scheduled jobs at all.');

                $owners = \App\Models\ScheduledJob::query()
                    ->whereIn('id', array_unique($rendered[1]))
                    ->pluck('store_id')
                    ->unique()
                    ->all();

                $this->assertSame([$two], $owners,
                    'OpenCart settings showed scheduled jobs belonging to another store.');
            } else {
                $this->assertStringContainsString('/channels/opencart/'.$two.'/', $content,
                    "OpenCart {$label} emitted no link scoped to the store it was asked for.");
            }
        }
    }

    public function test_every_opencart_interior_names_its_store_in_the_content(): void
    {
        [, $two] = $this->seedOpencartStores();
        $groupTwo = OpenCartProductGroup::create(['opencart_setting_id' => $two, 'name' => 'Looms']);
        $user = $this->opencartManager();

        foreach ($this->opencartInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "OpenCart {$label}");

            $this->assertStringContainsString('Cable Loft', $content,
                "OpenCart {$label} does not name its store anywhere in the page body.");
            $this->assertStringNotContainsString('Parts Yard', $content,
                "OpenCart {$label} names the wrong store.");
        }
    }

    public function test_an_operator_with_no_opencart_permission_is_refused_every_opencart_interior(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);
        $user = $this->outsider();

        foreach ($this->opencartInteriorUrls($one, $group->id) as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), $label);
        }
    }

    public function test_guests_are_redirected_away_from_every_opencart_interior(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);

        foreach ($this->opencartInteriorUrls($one, $group->id) as $label => $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_opencart_read_tier_is_not_offered_write_controls(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);
        $user = $this->opencartViewer();

        $groups = $this->actingAs($user)->get(route('ext.opencart.product-groups.index', $one))->assertOk();
        $groups->assertDontSee(route('ext.opencart.product-groups.create', $one), false);
        $groups->assertDontSee('Delete group');

        $form = $this->actingAs($user)->get(route('ext.opencart.product-groups.edit', [$one, $group->id]))->assertOk();
        $form->assertDontSee('Save product group');
        $this->assertMatchesRegularExpression('/name="name"[^>]*\bdisabled\b/', $form->getContent());

        $products = $this->actingAs($user)->get(route('ext.opencart.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertDontSee(route('ext.opencart.product-groups.push', [$one, $group->id]), false);
        $products->assertDontSee(route('ext.opencart.product-groups.addProducts', [$one, $group->id]), false);

        $settings = $this->actingAs($user)->get(route('ext.opencart.settings.show', ['store' => $one]))->assertOk();
        foreach ([
            route('ext.opencart.test'),
            route('ext.opencart.push'),
            route('ext.opencart.push_qty'),
            route('ext.opencart.verify_password'),
            route('ext.opencart.fetch_oc_statuses'),
            route('ext.opencart.save_status_map'),
        ] as $url) {
            $settings->assertDontSee($url, false);
        }

        $settings->assertSee('data-sync-url=""', false);
        $settings->assertSee('data-push-url=""', false);
        $settings->assertSee('data-verify-url=""', false);
        $settings->assertSee('data-test-url=""', false);

        $settings->assertDontSee('Save connection');
        $settings->assertDontSee('Save the window');
        $settings->assertDontSee('Save review setting');
        $settings->assertDontSee('Test connection');
        $settings->assertDontSee('Delete store');
        $settings->assertDontSee('action="'.e(route('ext.opencart.destroy', $one)).'"', false);
        $settings->assertDontSee('First import');

        $html = $settings->getContent();
        foreach (['store_name', 'base_url', 'api_key', 'enabled'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
        $this->assertStringNotContainsString('oc-key-one', $html,
            'The read tier was sent the store API key.');
    }

    public function test_the_opencart_manage_tier_is_offered_those_same_controls(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);
        $user = $this->opencartManager();

        $this->actingAs($user)->get(route('ext.opencart.product-groups.index', $one))
            ->assertOk()->assertSee(route('ext.opencart.product-groups.create', $one), false);

        $this->actingAs($user)->get(route('ext.opencart.product-groups.edit', [$one, $group->id]))
            ->assertOk()->assertSee('Save product group');

        $products = $this->actingAs($user)->get(route('ext.opencart.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertSee(route('ext.opencart.product-groups.push', [$one, $group->id]), false);
        $products->assertSee(route('ext.opencart.product-groups.addProducts', [$one, $group->id]), false);

        $settings = $this->actingAs($user)->get(route('ext.opencart.settings.show', ['store' => $one]))->assertOk();
        $settings->assertSee('action="'.e(route('ext.opencart.stores.destroy', ['store' => $one])).'"', false);
        foreach ([
            route('ext.opencart.save'),
            route('ext.opencart.test'),
            route('ext.opencart.sync'),
            route('ext.opencart.push'),
            route('ext.opencart.push_qty'),
            route('ext.opencart.verify_password'),
            route('ext.opencart.fetch_oc_statuses'),
            route('ext.opencart.save_status_map'),
            route('ext.opencart.sync_date'),
            route('ext.opencart.save_review_settings'),
        ] as $url) {
            $settings->assertSee($url, false);
        }
    }

    public function test_the_opencart_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        [$one] = $this->seedOpencartStores();
        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cables']);
        $user = $this->opencartViewer();

        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.save'), [
            'store_id' => $one, 'base_url' => 'https://x.test', 'api_key' => 'x',
        ]), 'save');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.opencart.destroy', $one)), 'destroy');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.test'), ['store_id' => $one]), 'test');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.sync'), ['store_id' => $one, 'entity' => 'orders']), 'sync');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.push'), ['store_id' => $one]), 'push');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.push_qty'), ['store_id' => $one]), 'push_qty');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.verify_password'), ['password' => 'x']), 'verify_password');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.fetch_oc_statuses'), ['store_id' => $one]), 'fetch_oc_statuses');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.save_status_map'), ['store_id' => $one]), 'save_status_map');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.sync_date'), ['store_id' => $one]), 'sync_date');
        $this->assertDenied($this->actingAs($user)->post(route('ext.opencart.save_review_settings'), ['store_id' => $one]), 'save_review_settings');
        $this->assertDenied($this->actingAs($user)->get(route('ext.opencart.product-groups.create', $one)), 'product-groups.create');
    }

    public function test_an_opencart_save_for_one_store_leaves_the_other_store_untouched(): void
    {
        [$one, $two] = $this->seedOpencartStores();

        $before = OpenCartSetting::findOrFail($two);
        $beforeTwo = [
            'store_name' => $before->store_name,
            'base_url' => $before->base_url,
            'api_key' => $before->api_key,
            'enabled' => (bool) $before->enabled,
            'brand_color' => $before->brand_color,
            'sync_last_days' => $before->sync_last_days,
        ];

        $this->actingAs($this->opencartManager())->post(route('ext.opencart.save'), [
            'store_id' => $one,
            'store_name' => 'Parts Yard Renamed',
            'brand_color' => '#ddeeff',
            'base_url' => 'https://renamed.opencart.test',
            'api_key' => 'oc-key-one-rotated',
            'sync_orders_from' => '2026-02-20',
            'enabled' => '1',
        ])->assertRedirect();

        $saved = OpenCartSetting::findOrFail($one);
        $this->assertSame('Parts Yard Renamed', $saved->store_name);
        $this->assertSame('#ddeeff', $saved->brand_color);
        $this->assertSame('https://renamed.opencart.test', $saved->base_url);
        $this->assertSame('oc-key-one-rotated', $saved->api_key);
        $this->assertSame('2026-02-20', $saved->sync_orders_from->format('Y-m-d'));
        $this->assertTrue((bool) $saved->enabled);

        $after = OpenCartSetting::findOrFail($two);
        $this->assertSame($beforeTwo['store_name'], $after->store_name);
        $this->assertSame($beforeTwo['base_url'], $after->base_url);
        $this->assertSame($beforeTwo['api_key'], $after->api_key);
        $this->assertSame($beforeTwo['enabled'], (bool) $after->enabled);
        $this->assertSame($beforeTwo['brand_color'], $after->brand_color);
        $this->assertSame($beforeTwo['sync_last_days'], $after->sync_last_days);
    }

    public function test_an_opencart_save_with_a_blank_key_keeps_the_stored_one(): void
    {
        [$one] = $this->seedOpencartStores();

        $this->actingAs($this->opencartManager())->post(route('ext.opencart.save'), [
            'store_id' => $one,
            'store_name' => 'Parts Yard Renamed',
            'base_url' => 'https://one.opencart.test',
            'api_key' => '',
        ])->assertRedirect();

        $saved = OpenCartSetting::findOrFail($one);
        $this->assertSame('Parts Yard Renamed', $saved->store_name, 'The rest of the form still saved.');
        $this->assertSame('oc-key-one', $saved->api_key, 'The stored key survived a blank submission.');
    }

    public function test_an_opencart_save_with_a_new_key_replaces_the_stored_one(): void
    {
        [$one] = $this->seedOpencartStores();

        $this->actingAs($this->opencartManager())->post(route('ext.opencart.save'), [
            'store_id' => $one,
            'base_url' => 'https://one.opencart.test',
            'api_key' => 'oc-key-one-replaced',
        ])->assertRedirect();

        $this->assertSame('oc-key-one-replaced', OpenCartSetting::findOrFail($one)->api_key);
    }

    public function test_a_new_opencart_store_still_requires_the_api_key(): void
    {
        $this->actingAs($this->opencartManager())->post(route('ext.opencart.save'), [
            'base_url' => 'https://new.opencart.test',
        ])->assertSessionHasErrors('api_key');

        $this->assertSame(0, OpenCartSetting::query()->count());
    }

    public function test_the_opencart_api_key_never_reaches_the_page_source_for_either_tier(): void
    {
        [$one] = $this->seedOpencartStores();

        foreach ([$this->opencartManager(), $this->opencartViewer()] as $user) {
            $html = $this->actingAs($user)->get(route('ext.opencart.settings.show', ['store' => $one]))
                ->assertOk()->getContent();

            $this->assertStringNotContainsString('oc-key-one', $html,
                'The stored API key reached the page source.');
        }
    }

    public function test_the_opencart_switches_can_actually_be_turned_off(): void
    {
        [$one] = $this->seedOpencartStores();

        $html = $this->actingAs($this->opencartManager())
            ->get(route('ext.opencart.settings.show', ['store' => $one]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="enabled" value="0"', $html);
        $this->assertStringNotContainsString('name="review_auto_approve" value="0"', $html);

        $this->actingAs($this->opencartManager())->post(route('ext.opencart.save_review_settings'), [
            'store_id' => $one,
        ])->assertRedirect();

        $this->assertFalse((bool) OpenCartSetting::findOrFail($one)->review_auto_approve);
    }

    public function test_the_opencart_status_map_save_is_scoped_to_its_own_store(): void
    {
        [$one, $two] = $this->seedOpencartStores();

        OpenCartOrderStatusMap::create([
            'opencart_setting_id' => $two, 'oc_status_id' => 3,
            'oc_status_name' => 'Shipped', 'order_status_id' => 3,
        ]);

        $this->actingAs($this->opencartManager())->postJson(route('ext.opencart.save_status_map'), [
            'store_id' => $one,
            'mappings' => [
                ['oc_status_id' => 3, 'oc_status_name' => 'Shipped', 'order_status_id' => 5],
            ],
        ])->assertOk();

        $this->assertSame(5, (int) OpenCartOrderStatusMap::where('opencart_setting_id', $one)
            ->where('oc_status_id', 3)->value('order_status_id'));
        $this->assertSame(3, (int) OpenCartOrderStatusMap::where('opencart_setting_id', $two)
            ->where('oc_status_id', 3)->value('order_status_id'));
    }

    public function test_opencart_interiors_render_their_seeded_records(): void
    {
        [$one] = $this->seedOpencartStores();
        $productId = $this->seedCatalogProduct('Patch Cable', 'PC-15');

        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => 'Cable Batch']);
        DB::table('opencart_product_group_products')->insert([
            'opencart_product_group_id' => $group->id, 'product_id' => $productId,
        ]);

        OpenCartOrderStatusMap::create([
            'opencart_setting_id' => $one, 'oc_status_id' => 5,
            'oc_status_name' => 'Complete', 'order_status_id' => 0,
        ]);
        OpenCartSyncLog::create([
            'opencart_setting_id' => $one, 'entity_type' => 'orders', 'direction' => 'pull',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'completed_at' => now(),
            'records_processed' => 9, 'records_updated' => 2, 'records_failed' => 0,
        ]);

        $user = $this->opencartManager();

        $groups = $this->actingAs($user)->get(route('ext.opencart.product-groups.index', $one))->assertOk();
        $groups->assertSee('Cable Batch');

        $products = $this->actingAs($user)->get(route('ext.opencart.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertSee('Patch Cable');
        $products->assertSee('PC-15');

        $settings = $this->actingAs($user)->get(route('ext.opencart.settings.show', ['store' => $one]))->assertOk();
        $settings->assertSee('Complete');
        $settings->assertSee('Sync Orders');
        $settings->assertSee('Push Stock');
        $settings->assertSee('Push Price');
        $settings->assertSee('Push Reviews');
        $settings->assertDontSee('schedule:run');
    }

    public function test_opencart_channel_sourced_names_are_escaped(): void
    {
        [$one] = $this->seedOpencartStores();
        $payload = '<img src=x onerror=alert(1)>';

        $group = OpenCartProductGroup::create(['opencart_setting_id' => $one, 'name' => $payload]);
        OpenCartOrderStatusMap::create([
            'opencart_setting_id' => $one, 'oc_status_id' => 11,
            'oc_status_name' => $payload, 'order_status_id' => 0,
        ]);
        OpenCartSyncLog::create([
            'opencart_setting_id' => $one, 'entity_type' => 'orders', 'direction' => 'pull',
            'status' => 'failed', 'started_at' => now(), 'completed_at' => now(),
            'records_processed' => 0, 'records_updated' => 0, 'records_failed' => 1,
            'error_message' => $payload,
        ]);

        $user = $this->opencartManager();

        foreach ([
            route('ext.opencart.product-groups.index', $one),
            route('ext.opencart.product-groups.edit', [$one, $group->id]),
            route('ext.opencart.settings.show', ['store' => $one]),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=', $html, "Unescaped output on {$url}");
        }
    }

    public function test_one_channels_manage_tier_opens_nothing_on_the_others(): void
    {
        $this->seedSetting();
        $this->seedTiktokSetting();
        [$ventaCartStore] = $this->seedVentaStores();
        [$opencartStore] = $this->seedOpencartStores();

        $ventaCartGroup = VentaCartProductGroup::create(['ventacart_setting_id' => $ventaCartStore, 'name' => 'Pedals']);
        $opencartGroup = OpenCartProductGroup::create(['opencart_setting_id' => $opencartStore, 'name' => 'Cables']);

        $shopeeUrls = $this->interiorUrls();
        $tiktokUrls = $this->tiktokInteriorUrls();
        $ventaCartUrls = $this->ventaCartInteriorUrls($ventaCartStore, $ventaCartGroup->id);
        $opencartUrls = $this->opencartInteriorUrls($opencartStore, $opencartGroup->id);

        $matrix = [
            'shopee manager' => [$this->manager(), array_merge($tiktokUrls, $ventaCartUrls, $opencartUrls)],
            'tiktok manager' => [$this->tiktokManager(), array_merge($shopeeUrls, $ventaCartUrls, $opencartUrls)],
            'ventacart manager' => [$this->ventaCartManager(), array_merge($shopeeUrls, $tiktokUrls, $opencartUrls)],
            'opencart manager' => [$this->opencartManager(), array_merge($shopeeUrls, $tiktokUrls, $ventaCartUrls)],
        ];

        foreach ($matrix as $who => [$user, $urls]) {
            foreach ($urls as $label => $url) {
                $this->assertDenied($this->actingAs($user)->get($url), "{$who} reached {$label}");
            }
        }
    }

    public function test_the_ventacart_save_gate_separates_the_two_tiers_on_one_route(): void
    {
        [$one] = $this->seedVentaStores();

        $payload = [
            'store_id' => $one,
            'store_name' => 'Renamed By Test',
            'base_url' => 'https://gate.ventacart.test',
            'api_token' => 'gate-token',
        ];

        $this->assertDenied(
            $this->actingAs($this->ventaCartViewer())->post(route('ext.ventacart.save'), $payload),
            'ventacart save, viewer'
        );
        $this->assertSame('Gear Depot', VentaCartSetting::findOrFail($one)->store_name);

        $this->actingAs($this->ventaCartManager())->post(route('ext.ventacart.save'), $payload)->assertRedirect();
        $this->assertSame('Renamed By Test', VentaCartSetting::findOrFail($one)->store_name);
    }

    public function test_a_channel_that_contributes_no_banners_shows_no_other_channels_banners(): void
    {
        $this->seedTiktokSetting(['expires_at' => now()->subWeeks(2)]);

        [$ventaCartStore] = $this->seedVentaStores();
        $ventaCartGroup = VentaCartProductGroup::create(['ventacart_setting_id' => $ventaCartStore, 'name' => 'Pedals']);

        foreach ($this->ventaCartInteriorUrls($ventaCartStore, $ventaCartGroup->id) as $label => $url) {
            $this->actingAs($this->ventaCartManager())->get($url)->assertOk()
                ->assertDontSee('TikTok Shop: Sign-in expired.', false);
        }

        [$ocStore] = $this->seedOpencartStores();
        $ocGroup = OpenCartProductGroup::create(['opencart_setting_id' => $ocStore, 'name' => 'Pedals']);

        foreach ($this->opencartInteriorUrls($ocStore, $ocGroup->id) as $label => $url) {
            $this->actingAs($this->opencartManager())->get($url)->assertOk()
                ->assertDontSee('TikTok Shop: Sign-in expired.', false);
        }
    }

    public function test_a_channel_still_shows_its_own_banner(): void
    {
        $this->seedTiktokSetting(['expires_at' => now()->subWeeks(2)]);

        $this->actingAs($this->tiktokManager())
            ->get(route('ext.tiktok.index'))
            ->assertOk()
            ->assertSee('TikTok Shop: Sign-in expired.', false);
    }
}
