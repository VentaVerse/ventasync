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
use Extensions\shopee\Models\ShopeeUnmatchedItem;
use Extensions\shopee\ShopeeExtension;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Models\LazadaUnmatchedItem;
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
use Extensions\venta\Models\VentaApiLog;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Models\VentaSyncLog;
use Extensions\venta\VentaExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Round C, Task 1: Shopee's five remaining workspace interiors (Listings,
 * Product Groups, Categories, Logistics, Settings) rebuilt on the shared
 * anatomies and out of the .x-legacy boundary.
 *
 * What this suite is actually for, in order of how much it would hurt to
 * lose:
 *
 *   1. The OAuth regression guard. /shopee is Shopee's registered redirect
 *      URI. ShopeeController::index() reads ?code= and ?shop_id= off that
 *      request and exchanges them for a token before the view renders. A
 *      redesign of the view cannot break it, and the only way to know is to
 *      drive the real request under Http::fake and assert the exchange went
 *      out on the wire with the right URL, method and body.
 *   2. Permission gating in both directions. These five views replaced a
 *      hardcoded `$canManageShopee = true` with the real permission check,
 *      so a read-tier operator must now see the pages and NOT the write
 *      controls, and the routes behind those controls must still 403.
 *   3. The settings save round trip, field by field, against the exact
 *      census ShopeeController::save() validates.
 *   4. That every page renders at all, outside the legacy boundary.
 */
class WorkspaceInteriorsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Idempotent extension setup, the same shape ChannelWorkspaceTest and
     * LazadaWorkspaceTest use: install() is updateOrCreate(), enable() is a
     * plain update, and $this->app->register() returns the already-registered
     * provider rather than double-booting one.
     *
     * refreshNameLookups() is load-bearing. Registering the provider loads
     * Shopee's routes into the RouteCollection, but the separate name-lookup
     * table that route() and Route::has() read is only rebuilt on demand.
     * Without this call every route() in these five views throws, and the
     * render tests would fail for a reason that has nothing to do with the
     * views.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        // Task 2 brought Lazada's five interiors onto the same anatomies, so
        // both extensions are booted here and the name lookups are rebuilt
        // once, after both have loaded their routes.
        $manager->install('lazada');
        $manager->enable('lazada');
        // Task 3 brought the remaining eleven interiors onto the same
        // anatomies: TikTok's three, and the two multi-store channels' four
        // each. All five extensions boot here and the name lookups are rebuilt
        // once, after every one of them has loaded its routes.
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $manager->install('venta');
        $manager->enable('venta');
        $manager->install('opencart');
        $manager->enable('opencart');

        $this->app->register(ShopeeExtension::class);
        $this->app->register(LazadaExtension::class);
        $this->app->register(TiktokExtension::class);
        $this->app->register(VentaExtension::class);
        $this->app->register(OpencartExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * Real read tier: view_shopee and nothing else. This is a permission the
     * extension manifest genuinely seeds (extensions/shopee/extension.json),
     * unlike core's view_orders, so this operator is reachable on a live
     * database and the read-tier branch of these views is not dead code.
     */
    private function viewer(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Viewer']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Real manage tier: manage_shopee, which every write route demands. */
    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Manager']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Holds no Shopee permission at all. */
    private function outsider(): User
    {
        $group = UserGroup::create(['name' => 'Interiors Outsider']);

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * App\Http\Middleware\EnsurePermission does NOT 403 an HTML caller. It
     * redirects (to the referer, else /dashboard) and flashes the denial into
     * session('error'), which layouts render. Asserting a 403 here would be
     * asserting against a middleware this app does not have, so the shape
     * checked is the real one, and the flashed error is checked too - a bare
     * assertRedirect() would also pass on a successful POST.
     */
    private function assertDenied(\Illuminate\Testing\TestResponse $response, string $context): void
    {
        $response->assertRedirect();
        $response->assertSessionHas('error', "You don't have permission to do this action.");
        $this->assertTrue(true, $context);
    }

    /** @return array<string, string> route name => url */
    private function interiorUrls(): array
    {
        return [
            'settings'       => route('ext.shopee.index'),
            'listings'       => route('ext.shopee.products.index'),
            'product-groups' => route('ext.shopee.product-groups.index'),
            'categories'     => route('ext.shopee.categories.index'),
            'logistics'      => route('ext.shopee.logistics.index'),
        ];
    }

    /**
     * Enough of a settings row that the Connection tab renders every branch:
     * a live mode with credentials present, so the masked-field and
     * token-state paths are exercised rather than skipped.
     */
    private function seedSetting(array $overrides = []): ShopeeSetting
    {
        return ShopeeSetting::create(array_merge([
            'mode' => 'live',
            'partner_id' => 200123,
            'partner_key' => encrypt('live-partner-key'),
            'shop_id' => 900456,
            'access_token' => encrypt('live-access-token'),
            'region' => 'ph',
            'api_logging' => true,
        ], $overrides));
    }

    /* =================================================================
       1. Render
       ================================================================= */

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

    /**
     * The point of the whole task. `.x-legacy` was applied by
     * layouts/channel.blade.php as the DEFAULT of @yield('content-class'),
     * carried by any page that had not opted out. Fix round 5 retired the
     * boundary entirely: the default is now '' and the per-view opt-outs
     * that used to override it are gone as redundant. This still asserts the
     * rendered <main> carries no legacy class, now proving the boundary is
     * unreachable rather than that this page happened to opt out.
     */
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
                'class="x-content "',
                $html,
                "{$label} did not render the opted-out content class."
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

    /**
     * Channel-sourced strings reach these pages straight from the
     * marketplace and from the catalogue. Blade escapes them; this proves
     * it, because the redesign moved every one of these values into new
     * markup and an @{!! !!} slip anywhere would be a stored XSS.
     */
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

        // M-5: Listings and Settings (log-row) previously had no payload
        // seeded at all, so they proved nothing either way.
        $this->seedCatalogueProduct('<svg onload=alert(4)>', 'CS-XSS-1');
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
            route('ext.shopee.index') => ['<i>alert(5)</i>', '&lt;i&gt;alert(5)&lt;/i&gt;'],
        ] as $url => [$raw, $escaped]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            // Never as raw markup...
            $this->assertStringNotContainsString($raw, $html, "Unescaped output on {$url}");
            // ...but genuinely rendered as text, not merely absent from the
            // page entirely (which would also satisfy the check above).
            $this->assertStringContainsString($escaped, $html, "Payload never reached {$url} at all");
        }
    }

    /* =================================================================
       2. Gating, both directions
       ================================================================= */

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

    /**
     * The read tier is a real, reachable tier now that these views ask the
     * permission system instead of hardcoding true. It must not be offered
     * controls the routes will refuse.
     */
    public function test_the_read_tier_is_not_offered_write_controls(): void
    {
        $this->seedSetting();
        // M-2: the Delete-all-logs form only renders when there is something
        // to delete, so without a seeded log the assertDontSee() below on
        // clear_api_logs would pass for a manager too - proving nothing
        // about the read tier specifically. A row makes the control exist
        // for whoever is allowed to see it.
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
        $listings->assertDontSee(route('ext.shopee.products.bulk_push'), false);
        $listings->assertDontSee(route('ext.shopee.products.add'), false);

        // The settings page is deliberately different from the four lists.
        // A read-tier operator is meant to be able to READ the credentials
        // page, so the fields still render - every one of them disabled, the
        // same treatment the status-mapping selects already carried. What
        // disappears is every control that writes.
        $settings = $this->actingAs($user)->get(route('ext.shopee.index'))->assertOk();
        $settings->assertDontSee(route('ext.shopee.toggle_mode'), false);
        $settings->assertDontSee(route('ext.shopee.token_get'), false);
        $settings->assertDontSee(route('ext.shopee.token_refresh'), false);
        $settings->assertDontSee(route('ext.shopee.authorize'), false);
        $settings->assertDontSee(route('ext.shopee.purge_raw'), false);
        $settings->assertDontSee(route('ext.shopee.explorer_run'), false);
        $settings->assertDontSee(route('ext.shopee.clear_api_logs'), false);
        $settings->assertDontSee(route('ext.shopee.logistics_channels'), false);
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
        // The catalogue typeahead only exists where there is something to
        // link, so the queue needs an entry for that assertion to mean
        // anything.
        ShopeeUnmatchedItem::create([
            'shopee_item_id' => 10076385934,
            'shopee_model_id' => 0,
            'item_name' => 'Unmatched pedal',
            'sku' => 'UNM-1',
            'status' => 'unmatched',
        ]);
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.shopee.categories.index'))
            ->assertOk()->assertSee(route('ext.shopee.categories.fetch'), false);

        $this->actingAs($user)->get(route('ext.shopee.logistics.index'))
            ->assertOk()->assertSee(route('ext.shopee.logistics.fetch'), false);

        $this->actingAs($user)->get(route('ext.shopee.product-groups.index'))
            ->assertOk()->assertSee(route('ext.shopee.product-groups.create'), false);

        $this->actingAs($user)->get(route('ext.shopee.products.index'))
            ->assertOk()
            ->assertSee(route('ext.shopee.products.bulk_push'), false)
            ->assertSee(route('ext.shopee.products.search_catalog'), false);

        $this->actingAs($user)->get(route('ext.shopee.index'))
            ->assertOk()
            ->assertSee(route('ext.shopee.save'), false)
            ->assertSee(route('ext.shopee.token_get'), false);
    }

    /**
     * The UI hiding a control is a courtesy; the middleware is the gate.
     * Every write route the five views drive must refuse a read-tier
     * operator on its own.
     */
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
            route('ext.shopee.toggle_logging'),
            route('ext.shopee.purge_raw'),
            route('ext.shopee.logistics_channels'),
            route('ext.shopee.categories.fetch'),
            route('ext.shopee.logistics.fetch'),
            route('ext.shopee.products.bulk_push'),
            route('ext.shopee.products.bulk_delete'),
            route('ext.shopee.products.sync_unmatched'),
            route('ext.shopee.products.rebuild_cache'),
        ];

        foreach ($posts as $url) {
            $this->assertDenied($this->actingAs($user)->post($url, []), $url);
        }

        $this->assertDenied($this->actingAs($user)->delete(route('ext.shopee.clear_api_logs')), 'clear logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.shopee.product-groups.create')), 'group create');
        $this->assertDenied($this->actingAs($user)->get(route('ext.shopee.products.add')), 'listing create');

        // Nothing was written by any of the refused calls.
        $this->assertSame('live', ShopeeSetting::query()->first()->mode);
    }

    /**
     * Mutation proof for one gate. If the read tier were silently sufficient
     * for a write - which is exactly what a copy-paste of the wrong
     * middleware string produces - this assertion would pass with a 302
     * instead of a 403, so the test is only meaningful because the manage
     * tier is separately shown to succeed on the same route with the same
     * payload.
     */
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
            ->assertRedirect(route('ext.shopee.index'));

        $this->assertSame(111222, (int) ShopeeSetting::query()->first()->partner_id);
    }

    /* =================================================================
       3. Settings save round trip, the full validated census
       ================================================================= */

    /**
     * ShopeeController::save() validates exactly eleven names: env plus five
     * live and five sandbox fields. Both halves are posted here, each with
     * the env that selects it, and every column is read back.
     */
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
        ])->assertRedirect(route('ext.shopee.index'))->assertSessionHas('status');

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
        ])->assertRedirect(route('ext.shopee.index'));

        $row->refresh();
        $this->assertSame('sandbox', $row->mode);
        $this->assertSame(100111, (int) $row->sandbox_partner_id);
        $this->assertSame(800111, (int) $row->sandbox_shop_id);
        $this->assertSame('sg', $row->sandbox_region);
        $this->assertSame('the-sandbox-partner-key', decrypt($row->sandbox_partner_key));
        $this->assertSame('the-sandbox-access-token', decrypt($row->sandbox_access_token));

        // The live half survived the sandbox save untouched. This is the
        // whole reason the form carries a hidden `env`.
        $this->assertSame(200999, (int) $row->partner_id);
        $this->assertSame('the-live-partner-key', decrypt($row->partner_key));
    }

    /**
     * The masked-credential contract. resources/js/credential-mask.js blanks
     * an untouched •••••••• field before the form posts, and the controller
     * reads a blank as "keep what is stored". A save that omits the secrets
     * must therefore leave them exactly as they were, or every operator who
     * changes only the region wipes their own partner key.
     */
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
        ])->assertRedirect(route('ext.shopee.index'));

        $row = ShopeeSetting::query()->first();
        $this->assertSame('live-partner-key', decrypt($row->partner_key));
        $this->assertSame('live-access-token', decrypt($row->access_token));
        $this->assertSame(200123, (int) $row->partner_id);
        $this->assertSame(900456, (int) $row->shop_id);
        // Region is a dropdown, so a blank there is the explicit "Not set"
        // choice and DOES overwrite. This asserts the chosen value landed.
        $this->assertSame('sg', $row->region);
    }

    public function test_the_environment_switch_posts_the_mode_it_names(): void
    {
        $this->seedSetting(['mode' => 'live']);
        $user = $this->manager();

        $this->actingAs($user)->post(route('ext.shopee.toggle_mode'), ['mode' => 'sandbox'])
            ->assertRedirect(route('ext.shopee.index'));

        $this->assertSame('sandbox', ShopeeSetting::query()->first()->mode);

        $this->actingAs($user)->post(route('ext.shopee.toggle_mode'), ['mode' => 'live'])
            ->assertRedirect(route('ext.shopee.index'));

        $this->assertSame('live', ShopeeSetting::query()->first()->mode);
    }

    public function test_the_logging_switch_saves_without_a_page_reload(): void
    {
        $this->seedSetting(['api_logging' => true]);
        $user = $this->manager();

        $this->actingAs($user)
            ->postJson(route('ext.shopee.toggle_logging'), ['api_logging' => 0])
            ->assertOk()
            ->assertJson(['ok' => true, 'api_logging' => false]);

        $this->assertFalse((bool) ShopeeSetting::query()->first()->api_logging);
    }

    /* =================================================================
       4. THE OAUTH REGRESSION GUARD
       ================================================================= */

    /**
     * /shopee is the URL registered as this install's redirect URI at the
     * Shopee Open Platform. Shopee sends the merchant's browser back to it
     * with ?code=&shop_id=, and ShopeeController::index() exchanges the code
     * for a token before rendering anything.
     *
     * This drives that exact request and pins the wire it produced:
     *
     *   - one POST to the live host's /api/v2/auth/token/get
     *   - partner_id, timestamp and sign in the query string
     *   - code, shop_id and partner_id in the JSON body
     *   - the returned access and refresh tokens persisted, encrypted
     *   - a redirect back to the bare settings URL, so a page refresh
     *     cannot replay a one-time-use code
     *
     * If a future change to the settings view (or to this route's
     * middleware) ever stops that exchange from firing, this fails.
     */
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

        $response->assertRedirect(route('ext.shopee.index'));
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

    /**
     * The sandbox half of the same contract: the callback writes to the
     * sandbox columns and calls the sandbox host, never the live ones. A
     * redesign that quietly read $setting->partner_id instead of the
     * env-aware helper would spill sandbox tokens into production columns
     * and this is what catches it.
     */
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
            ->assertRedirect(route('ext.shopee.index'));

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

    /**
     * No code, no shop_id: the page is just the settings page and nothing is
     * sent to Shopee. Without this the guard above could pass while the
     * controller fired an exchange on every ordinary page load.
     */
    public function test_a_plain_settings_visit_calls_shopee_at_all(): void
    {
        Http::fake();

        $this->seedSetting();

        $this->actingAs($this->manager())->get(route('ext.shopee.index'))->assertOk();

        Http::assertNothingSent();
    }

    /**
     * The redirect URI is displayed so it can be copied into the Shopee
     * console, and it must never be a settable field. A `name` on that input
     * would post it back into save(), which does not validate it and would
     * silently ignore it, leaving an operator who edited the box believing
     * they had changed something.
     */
    /**
     * M-1: the original version of this test asserted the redirect URI
     * appeared ANYWHERE on the page, which the sidebar's own link to
     * ext.shopee.index also satisfies - deleting the actual display left it
     * green. Mirrors TikTok's shape: pins the exact readonly, nameless
     * input(s) that carry it, both the live and sandbox copies.
     */
    public function test_the_redirect_uri_is_shown_but_not_submittable(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

        $redirect = preg_replace('/^http:/i', 'https:', route('ext.shopee.index'));

        $this->assertStringContainsString(e($redirect), $html);
        $this->assertSame(2, substr_count($html, 'value="'.e($redirect).'"'));

        // Every element carrying that value is readonly and has no name.
        preg_match_all('/<input[^>]*value="'.preg_quote(e($redirect), '/').'"[^>]*>/', $html, $matches);
        $this->assertCount(2, $matches[0]);
        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('readonly', $tag);
            $this->assertStringNotContainsString('name=', $tag);
        }

        $this->assertStringNotContainsString('name="redirect_uri"', $html);
        $this->assertStringNotContainsString('name="sandbox_redirect_uri"', $html);
    }

    /**
     * The three OAuth controls the settings view is not allowed to change.
     * Asserted as markup, because "the route exists" is not the same claim
     * as "the page still offers it".
     */
    public function test_the_settings_page_still_carries_every_oauth_control(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.e(route('ext.shopee.authorize')).'"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.shopee.token_get')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.shopee.token_refresh')).'"', $html);
        $this->assertStringContainsString('name="code"', $html);
    }

    /**
     * Every field name ShopeeController::save() validates has to exist in the
     * markup, or the redesign has quietly dropped a credential an operator
     * can no longer set. Both environments, because only one is visible at a
     * time but both are rendered.
     */
    public function test_the_settings_page_carries_the_whole_field_census(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

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

        // Both status-map surfaces, one key from each.
        $this->assertStringContainsString('name="map[READY_TO_SHIP]"', $html);
        $this->assertStringContainsString('name="map[REFUND_PAID]"', $html);
    }

    /**
     * I-2: the field census above proves both env forms exist, but not that
     * each carries the RIGHT hidden env value. A hidden `env` flipped from
     * `sandbox` to `live` on the sandbox credential form would survive it,
     * and every "sandbox" save would silently overwrite production
     * credentials instead. This pins each save form's hidden env to the
     * credential fields that live inside it.
     */
    public function test_each_shopee_credential_form_carries_its_own_env_value(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

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

    /**
     * Stored credentials render as a mask, never as the secret itself. The
     * redesign moved all ten of these inputs, so this is worth pinning: a
     * value="{{ $setting->partner_key }}" typo would put an encrypted, or
     * worse a decrypted, key into the HTML source.
     */
    public function test_stored_secrets_never_reach_the_page_source(): void
    {
        $this->seedSetting([
            'sandbox_partner_key' => encrypt('sandbox-partner-key'),
            'sandbox_access_token' => encrypt('sandbox-access-token'),
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

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

        $this->actingAs($this->manager())->get(route('ext.shopee.index'))
            ->assertOk()
            ->assertSee('/api/v2/shop/get_shop_info')
            ->assertSee('action="'.e(route('ext.shopee.clear_api_logs')).'"', false);
    }

    /* =================================================================
       5. LAZADA (Round C, Task 2)

       The same five interiors, one channel over: Listings, Product Groups,
       Categories, Brands (where Shopee has Logistics) and Settings.

       The OAuth story differs, and the difference is the reason this block
       does not simply mirror the Shopee one. Shopee's settings page IS its
       registered redirect URI, so its guard has to drive ?code= through the
       page itself. Lazada redirects to a separate, permanently exempt
       /lazada/callback route that this task did not touch. What the settings
       view CAN break is the set of controls that reach those surfaces: the
       readonly redirect display (which must name the callback route and must
       never be submittable), the two authorize links (the sandbox one
       carrying ?sandbox=1, which is what picks the sandbox app key), and the
       hidden `sandbox` flags on the exchange and refresh forms. Those are
       what is pinned here.
       ================================================================= */

    private function lazadaViewer(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Interiors Viewer']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function lazadaManager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada Interiors Manager']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_lazada'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** @return array<string, string> label => url */
    private function lazadaInteriorUrls(): array
    {
        return [
            'settings'       => route('ext.lazada.index'),
            'listings'       => route('ext.lazada.products.index'),
            'product-groups' => route('ext.lazada.product-groups.index'),
            'categories'     => route('ext.lazada.categories.index'),
            'brands'         => route('ext.lazada.brands.index'),
        ];
    }

    /**
     * Enough of a settings row that the Connection tab renders every branch:
     * live mode with credentials present, so the masked-field and token-state
     * paths are exercised rather than skipped.
     */
    private function seedLazadaSetting(array $overrides = []): LazadaSetting
    {
        return LazadaSetting::create(array_merge([
            'mode' => 'live',
            'region' => 'ph',
            'app_key' => '100123',
            'app_secret' => encrypt('live-app-secret'),
            'access_token' => encrypt('live-access-token'),
            'api_logging' => true,
        ], $overrides));
    }

    /* ---- render ---------------------------------------------------- */

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

    /**
     * The point of the whole task, Lazada's half. See the Shopee version of
     * this test for the boundary's retirement in fix round 5: the layout's
     * default content-class is now '' and the per-view opt-outs are gone.
     */
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
                'class="x-content "',
                $html,
                "Lazada {$label} did not render the opted-out content class."
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

        // The listing's catalogue product does not exist, which is a real
        // state on this page (a Lazada row whose ERP product was deleted) and
        // the branch the rebuilt cells fall through to. It DOES carry a Lazada
        // item id, so the row's overflow offers the uploaded-listing actions
        // and not Upload, exactly as the old menu branched.
        $this->actingAs($user)->get(route('ext.lazada.products.index'))
            ->assertOk()
            ->assertSee('778899')
            ->assertSee('No product')
            ->assertSee('action="'.e(route('ext.lazada.products.sync_quantity', $listing->id)).'"', false)
            ->assertSee('action="'.e(route('ext.lazada.products.delete_lazada', $listing->id)).'"', false)
            ->assertDontSee('action="'.e(route('ext.lazada.products.upload', $listing->id)).'"', false);
    }

    /**
     * Channel-sourced strings reach these pages straight from the marketplace
     * and from the catalogue. Blade escapes them; this proves it, because the
     * redesign moved every one of these values into new markup and an
     * unescaped slip anywhere would be a stored XSS.
     */
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

        LazadaUnmatchedItem::create([
            'lazada_item_id' => '990011',
            'item_name' => '<svg onload=alert(3)>',
            'sku' => 'UNM-LZ-1',
            'status' => 'unmatched',
        ]);

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

    /* ---- gating, both directions ----------------------------------- */

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

    /**
     * A Shopee viewer is an outsider to Lazada and the other way round. The
     * five views ask for their own channel's permission, so holding the wrong
     * channel's manage tier must not open these pages.
     */
    public function test_the_other_channels_manage_tier_opens_nothing_here(): void
    {
        $this->seedLazadaSetting();
        $this->seedSetting();

        // One user each, created outside the loops: UserGroup names are
        // unique, so building a fresh manager per iteration collides.
        $shopeeManager = $this->manager();
        $lazadaManager = $this->lazadaManager();

        // Shopee's manager, on Lazada's pages.
        foreach ($this->lazadaInteriorUrls() as $label => $url) {
            $this->assertDenied($this->actingAs($shopeeManager)->get($url), 'shopee manager on lazada '.$label);
        }

        // And the reverse, so this is not asserting a one-way accident.
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
        $listings->assertDontSee(route('ext.lazada.products.bulk_upload'), false);
        $listings->assertDontSee(route('ext.lazada.products.bulk_delete'), false);
        $listings->assertDontSee(route('ext.lazada.products.create'), false);
        $listings->assertDontSee(route('ext.lazada.products.sync_unmatched'), false);

        // The settings page is deliberately different from the four lists. A
        // read-tier operator is meant to be able to READ the credentials page,
        // so the fields still render, every one of them disabled. What
        // disappears is every control that writes.
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
        // The catalogue typeahead only exists where there is something to
        // link, so the queue needs an entry for that assertion to mean
        // anything.
        LazadaUnmatchedItem::create([
            'lazada_item_id' => '10076385934',
            'item_name' => 'Unmatched pedal',
            'sku' => 'UNM-LZ-2',
            'status' => 'unmatched',
        ]);
        $user = $this->lazadaManager();

        $this->actingAs($user)->get(route('ext.lazada.categories.index'))
            ->assertOk()->assertSee(route('ext.lazada.categories.fetch'), false);

        $this->actingAs($user)->get(route('ext.lazada.brands.index'))
            ->assertOk()->assertSee(route('ext.lazada.brands.fetch'), false);

        $this->actingAs($user)->get(route('ext.lazada.product-groups.index'))
            ->assertOk()->assertSee(route('ext.lazada.product-groups.create'), false);

        $this->actingAs($user)->get(route('ext.lazada.products.index'))
            ->assertOk()
            ->assertSee(route('ext.lazada.products.bulk_upload'), false)
            ->assertSee(route('ext.lazada.products.search_catalog'), false);

        $this->actingAs($user)->get(route('ext.lazada.index'))
            ->assertOk()
            ->assertSee(route('ext.lazada.save'), false)
            ->assertSee(route('ext.lazada.token_create'), false);
    }

    /**
     * The UI hiding a control is a courtesy; the middleware is the gate. Every
     * write route the five Lazada views drive must refuse a read-tier operator
     * on its own.
     */
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
            route('ext.lazada.toggle_logging'),
            route('ext.lazada.purge_raw'),
            route('ext.lazada.explorer_run'),
            route('ext.lazada.categories.fetch'),
            route('ext.lazada.brands.fetch'),
            route('ext.lazada.products.bulk_upload'),
            route('ext.lazada.products.bulk_delete'),
            route('ext.lazada.products.sync_unmatched'),
        ];

        foreach ($posts as $url) {
            $this->assertDenied($this->actingAs($user)->post($url, []), $url);
        }

        $this->assertDenied($this->actingAs($user)->delete(route('ext.lazada.clear_api_logs')), 'clear logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.product-groups.create')), 'group create');
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.products.create')), 'listing create');
        // The authorize link is the one write surface that lives outside the
        // /integrations prefix, so it is asserted separately.
        $this->assertDenied($this->actingAs($user)->get(route('ext.lazada.authorize')), 'authorize');

        // Nothing was written by any of the refused calls.
        $this->assertSame('live', LazadaSetting::query()->first()->mode);
    }

    /**
     * Mutation proof for one gate. If the read tier were silently sufficient
     * for a write, which is exactly what a copy-paste of the wrong middleware
     * string produces, this would pass on the denial half alone; it is only
     * meaningful because the manage tier is separately shown to succeed on the
     * SAME route with the SAME payload and change the SAME column.
     *
     * Proven by mutation: relaxing /lazada/save to
     * `perm:view_lazada|manage_lazada` makes this fail on the viewer half, and
     * the mutation was reverted.
     */
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

    /* ---- settings save round trip ---------------------------------- */

    /**
     * The riskiest form on the riskiest page. LazadaController::save()
     * validates exactly six names: env, the shared region, and two credentials
     * per environment. Both halves are posted here, each with the env that
     * selects it, and every column is read back.
     *
     * The two things that would be silently wrong if the rebuilt form got them
     * wrong, and that this pins:
     *
     *   - `region` is ONE field name in both forms, because the controller
     *     writes one shared column. A sandbox form posting `sandbox_region`
     *     would validate fine and save nothing.
     *   - the hidden `env` is what stops a sandbox save from wiping the live
     *     credentials, and it also sets `mode`.
     */
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

        // Region is shared, so the sandbox save moved it. That is the
        // documented behaviour and the reason the sandbox form's hint says so.
        $this->assertSame('sg', $row->region);

        // The live credentials survived the sandbox save untouched. This is
        // the whole reason the form carries a hidden `env`.
        $this->assertSame('100777', (string) $row->app_key);
        $this->assertSame('the-live-app-secret', decrypt($row->app_secret));
    }

    /**
     * The masked-credential contract. resources/js/credential-mask.js blanks
     * an untouched •••••••• field before the form posts, and the controller
     * reads a blank as "keep what is stored". A save that omits the secret
     * must therefore leave it exactly as it was, or every operator who changes
     * only the region wipes their own app secret.
     */
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
        // Region is a dropdown, so a blank there is the explicit "Not set"
        // choice and DOES overwrite. This asserts the chosen value landed.
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

    public function test_the_lazada_logging_switch_saves_without_a_page_reload(): void
    {
        $this->seedLazadaSetting(['api_logging' => true]);
        $user = $this->lazadaManager();

        $this->actingAs($user)
            ->postJson(route('ext.lazada.toggle_logging'), ['api_logging' => 0])
            ->assertOk()
            ->assertJson(['ok' => true, 'api_logging' => false]);

        $this->assertFalse((bool) LazadaSetting::query()->first()->api_logging);
    }

    /* ---- the OAuth surfaces this view is not allowed to change ------ */

    /**
     * The redirect URI is displayed so it can be copied into the Lazada
     * console. Two claims, both of which a redesign could break silently:
     *
     *   - it is the CALLBACK route, not this page. Lazada compares the string
     *     byte for byte, and pointing the box at the settings URL would send
     *     an operator to register a URL that never receives a code.
     *   - it has no `name`, so it is never posted. save() does not validate a
     *     redirect field and would ignore it, leaving an operator who edited
     *     the box believing they had changed something.
     */
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

    /**
     * The OAuth controls the settings view is not allowed to change, asserted
     * as markup, because "the route exists" is not the same claim as "the page
     * still offers it".
     *
     * The sandbox authorize link is the sharp one. redirectToAuth() reads
     * ?sandbox=1 off the query to decide which app key it sends, so a rebuilt
     * link that dropped the parameter would quietly authorise the PRODUCTION
     * app from a button labelled sandbox, and nothing would look wrong until a
     * live shop started answering test calls.
     */
    public function test_the_lazada_settings_page_still_carries_every_oauth_control(): void
    {
        $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.e(route('ext.lazada.authorize')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('ext.lazada.authorize').'?sandbox=1').'"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.token_create')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.token_refresh')).'"', $html);
        $this->assertStringContainsString('action="'.e(route('ext.lazada.sandbox_token_save')).'"', $html);
        $this->assertStringContainsString('name="code"', $html);

        // Both sandbox forms carry the hidden flag the controller branches on.
        $this->assertSame(
            2,
            substr_count($html, '<input type="hidden" name="sandbox" value="1">'),
            'The sandbox exchange and refresh forms must each carry the hidden sandbox flag.'
        );
    }

    /**
     * Every field name a Lazada settings write validates has to exist in the
     * markup, or the redesign has quietly dropped something an operator can no
     * longer set. Both environments, because only one is visible at a time but
     * both are rendered.
     */
    public function test_the_lazada_settings_page_carries_the_whole_field_census(): void
    {
        $this->seedLazadaSetting();

        $html = $this->actingAs($this->lazadaManager())
            ->get(route('ext.lazada.index'))->assertOk()->getContent();

        foreach ([
            // save()
            'env', 'region', 'app_key', 'app_secret', 'sandbox_app_key', 'sandbox_app_secret',
            // toggleMode(), tokenCreate(), saveSandboxToken(), purgeRaw()
            'mode', 'code', 'sandbox', 'sandbox_access_token', 'expires_in_days', 'days',
            // explorerRun()
            'method', 'api_path', 'auth_required', 'params_json', 'params_json_pretty',
        ] as $field) {
            $this->assertStringContainsString(
                'name="'.$field.'"',
                $html,
                "The Lazada settings page no longer carries a field named {$field}."
            );
        }

        // `region` is deliberately the same name in both environment forms.
        $this->assertSame(2, substr_count($html, 'name="region"'), 'Both environment forms must post the shared region field.');

        // Both status-map surfaces, one key from each.
        $this->assertStringContainsString('name="map[ready_to_ship]"', $html);
        $this->assertStringContainsString('name="map[refund_paid]"', $html);
    }

    /**
     * I-2: same gap as Shopee's. The field census proves both env forms
     * exist, not that each carries the right hidden env value. `region` is
     * deliberately shared between the two forms, so `app_key` versus
     * `sandbox_app_key` is what tells them apart.
     */
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

    /**
     * Stored credentials render as a mask, never as the secret itself. The
     * redesign moved all four of these inputs, so this is worth pinning: a
     * value="{{ $setting->app_secret }}" typo would put an encrypted, or worse
     * a decrypted, secret into the HTML source.
     */
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

    /**
     * The listings page kept its sortable header, which the old view drove
     * with the same sort/dir pair. Two things are pinned: the header link
     * carries both parameters, and the filter form carries them as hidden
     * fields so submitting a filter does not silently reset the sort.
     */
    public function test_the_lazada_listings_page_keeps_its_sort_contract(): void
    {
        $this->seedLazadaSetting();
        // The sortable header only exists when there are rows to sort; an
        // empty list renders the empty state and no <thead> at all.
        LazadaProduct::create(['product_id' => 4243, 'lazada_item_id' => '551122']);
        $user = $this->lazadaManager();

        $html = $this->actingAs($user)
            ->get(route('ext.lazada.products.index', ['sort' => 'quantity', 'dir' => 'asc']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="sort" value="quantity"', $html);
        $this->assertStringContainsString('name="dir" value="asc"', $html);
        // Clicking the column already sorted ascending flips it to descending.
        $this->assertStringContainsString('sort=quantity&amp;dir=desc', $html);
    }

    /* =================================================================
       ROUND C, TASK 3 - TikTok, Venta and OpenCart
       -----------------------------------------------------------------
       The remaining eleven interiors. Three of them are TikTok's, which
       is single-shop and OAuth-authenticated like Shopee and Lazada; the
       other eight belong to the two MULTI-STORE channels, and that is
       what most of what follows is really about.

       On a multi-store channel every one of these pages is scoped to one
       store by a {store} path segment, and the danger is not that a page
       fails to render but that it renders the right thing while acting
       on the wrong row. So the store-scoped assertions here are not
       "does the page mention the store": they are

         - every self-link on the page carries the store it arrived on,
           and never the other store's id;
         - the store is NAMED in the rendered content, not only in the
           breadcrumb, because the trail collapses to the leaf below
           768px;
         - a save posted for store 1 changes store 1's row and leaves
           store 2's row byte-identical. Both halves are asserted, which
           is the only way that claim means anything.
       ================================================================= */

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** An operator holding one channel's view tier and nothing else. */
    private function tierUser(string $label, array $permissions): User
    {
        // Unique per call: several tests below build the same tier twice, and
        // user_groups.name is unique.
        $group = UserGroup::create(['name' => $label.' '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function tiktokViewer(): User { return $this->tierUser('TikTok Viewer', ['view_tiktok']); }
    private function tiktokManager(): User { return $this->tierUser('TikTok Manager', ['manage_tiktok']); }
    private function ventaViewer(): User { return $this->tierUser('Venta Viewer', ['view_venta']); }
    private function ventaManager(): User { return $this->tierUser('Venta Manager', ['manage_venta']); }
    private function opencartViewer(): User { return $this->tierUser('OpenCart Viewer', ['view_opencart']); }
    private function opencartManager(): User { return $this->tierUser('OpenCart Manager', ['manage_opencart']); }

    /**
     * Enough of a TikTok settings row that the Connection tab renders every
     * branch: live mode with credentials and a shop, so the masked-field,
     * token-state and shop-facts paths are exercised rather than skipped.
     */
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
            'shop_name' => 'example TikTok',
            'region' => 'ph',
            'api_logging' => true,
        ], $overrides));
        $setting->save();

        return $setting;
    }

    /**
     * TWO stores per multi-store channel, always. One store cannot prove a
     * store-scoped claim: a page that ignored the segment entirely would
     * still pass every assertion against a single row.
     *
     * @return array{0:int,1:int} the two store ids, in creation order
     */
    private function seedVentaStores(): array
    {
        $one = VentaSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => 'https://one.venta.test',
            'api_token' => 'venta-token-one',
            'enabled' => true,
            'api_logging' => true,
            'sync_last_days' => 30,
            'warehouse_id' => 4,
            'brand_color' => '#059669',
        ]);

        $two = VentaSetting::create([
            'store_name' => 'Switch Bazaar',
            'base_url' => 'https://two.venta.test',
            'api_token' => 'venta-token-two',
            'enabled' => true,
            'api_logging' => false,
            'sync_last_days' => 14,
            'warehouse_id' => 9,
            'brand_color' => '#123456',
        ]);

        return [$one->id, $two->id];
    }

    /** @return array{0:int,1:int} */
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

    /** One catalogue product, enough for a group's Products page to have a row. */
    private function seedCatalogueProduct(string $name, string $sku): int
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

    /** @return array<string, string> label => url */
    private function tiktokInteriorUrls(): array
    {
        return [
            'settings'       => route('ext.tiktok.index'),
            'categories'     => route('ext.tiktok.categories.index'),
            'product-groups' => route('ext.tiktok.product-groups.index'),
        ];
    }

    /** @return array<string, string> label => url, all scoped to $store */
    private function ventaInteriorUrls(int $store, int $group): array
    {
        return [
            'settings'          => route('ext.venta.settings.show', ['store' => $store]),
            'product-groups'    => route('ext.venta.product-groups.index', $store),
            'group-form'        => route('ext.venta.product-groups.edit', [$store, $group]),
            'group-products'    => route('ext.venta.product-groups.products', [$store, $group]),
        ];
    }

    /** @return array<string, string> */
    private function opencartInteriorUrls(int $store, int $group): array
    {
        return [
            'settings'          => route('ext.opencart.settings.show', ['store' => $store]),
            'product-groups'    => route('ext.opencart.product-groups.index', $store),
            'group-form'        => route('ext.opencart.product-groups.edit', [$store, $group]),
            'group-products'    => route('ext.opencart.product-groups.products', [$store, $group]),
        ];
    }

    /**
     * The page's OWN markup, with the shared region in front of it removed.
     *
     * partials/legacy-page-contract.blade.php renders inside <main>, ahead of
     * @yield('content'): flashes, validation errors, and the layout banners
     * every enabled integration contributes. On a multi-store channel those
     * banners legitimately name and link OTHER stores, because they are the
     * whole workspace's warnings and not this page's. Asserting store scoping
     * against the raw <main> would therefore fail on markup this task does not
     * own, so the region is cut at its last element, the flash container.
     */
    private function pageContent(string $html, string $label): string
    {
        preg_match('/<main class="x-content[^"]*">(.*?)<\/main>/s', $html, $main);
        $inside = $main[1] ?? '';
        $this->assertNotEmpty($inside, "No content region rendered for {$label}.");

        $marker = '<div id="js-flash-container"></div>';
        $at = strpos($inside, $marker);
        $this->assertNotFalse($at, "The shared page contract did not render on {$label}.");

        return substr($inside, $at + strlen($marker));
    }

    /**
     * The rendered <main>, which is where the boundary class used to live.
     * The layout's default content-class is now '' (fix round 5), so this
     * proves the class is unreachable, not merely opted out per view.
     */
    private function assertBoundaryDropped(string $html, string $label): void
    {
        $this->assertStringNotContainsString(
            'class="x-content x-legacy"',
            $html,
            "{$label} is still inside the .x-legacy content boundary."
        );
        $this->assertStringContainsString(
            'class="x-content "',
            $html,
            "{$label} did not render the opted-out content class."
        );
    }

    /* =================================================================
       TIKTOK
       ================================================================= */

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
        // The permission list is joined for reading, not printed as a raw array.
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

        // The settings page is deliberately different from the two lists: a
        // read-tier operator can READ the credentials page, so the fields
        // render, every one of them disabled. What disappears is every control
        // that writes.
        $settings = $this->actingAs($user)->get(route('ext.tiktok.index'))->assertOk();

        // Every route whose whole surface disappears for a reader.
        foreach ([
            'ext.tiktok.toggle_mode', 'ext.tiktok.token_get',
            'ext.tiktok.token_refresh', 'ext.tiktok.shops', 'ext.tiktok.authorize',
            'ext.tiktok.explorer_run', 'ext.tiktok.packs_run',
            'ext.tiktok.toggle_logging', 'ext.tiktok.clear_api_logs', 'ext.tiktok.purge_raw',
        ] as $name) {
            $settings->assertDontSee(route($name), false);
        }

        // The credential save and the status-map save are different: their
        // FORMS still render, because they are what wraps the disabled fields
        // a reader is meant to be able to read. What must be gone is the
        // submit control, so that is what is asserted.
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
        // The Delete-all-logs form only exists when there is something to
        // delete, which is the pre-redesign rule kept unchanged.
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
            'ext.tiktok.token_refresh', 'ext.tiktok.shops', 'ext.tiktok.authorize',
            'ext.tiktok.explorer_run', 'ext.tiktok.packs_run',
            'ext.tiktok.toggle_logging', 'ext.tiktok.clear_api_logs', 'ext.tiktok.purge_raw',
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

    /**
     * The whole census TikTokController::save() reads, per environment, and
     * nothing it does not read. Two things are pinned that a redesign is
     * likeliest to break silently: the production form must carry `env=live`
     * and the sandbox form `env=sandbox`, because the controller chooses which
     * pair of columns to write from that one hidden field.
     */
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

        // Exactly one env=live form and one env=sandbox form. A second copy of
        // either would give the page two credential saves for one environment.
        $this->assertSame(1, substr_count($html, 'name="env" value="live"'));
        $this->assertSame(1, substr_count($html, 'name="env" value="sandbox"'));
    }

    /**
     * The OAuth surfaces, which are contracts registered at the TikTok Shop
     * Partner Center.
     *
     * The most important assertion here is the negative one. Lazada's sandbox
     * authorize link carries `?sandbox=1` because its controller reads that
     * query param; TikTok's does NOT, because redirectToAuth() reads the stored
     * `mode` column instead. Adding one would be inventing a parameter nothing
     * reads, and it is exactly the kind of thing a person porting Lazada's page
     * onto TikTok would add for consistency.
     */
    public function test_the_tiktok_settings_page_still_carries_every_oauth_control(): void
    {
        $this->seedTiktokSetting();
        $html = $this->actingAs($this->tiktokManager())->get(route('ext.tiktok.index'))->assertOk()->getContent();

        $authorize = route('ext.tiktok.authorize');

        // Two authorize links, one per environment panel, both the bare route.
        $this->assertSame(2, substr_count($html, 'href="'.e($authorize).'"'));
        $this->assertStringNotContainsString($authorize.'?sandbox', $html);
        $this->assertStringNotContainsString(e($authorize).'?sandbox', $html);
        $this->assertStringContainsString('rel="noopener"', $html);

        // No hidden environment flag on the exchange or the refresh: both read
        // the stored mode, so a `sandbox` field here would be dead weight that
        // a later reader would take for a contract.
        $this->assertStringNotContainsString('name="sandbox"', $html);

        $this->assertStringContainsString(route('ext.tiktok.token_get'), $html);
        $this->assertStringContainsString(route('ext.tiktok.token_refresh'), $html);
        $this->assertStringContainsString(route('ext.tiktok.shops'), $html);
    }

    /**
     * The redirect URI is shown so it can be copied into the Partner Center,
     * and must never be posted back as a settable field. Both copies (live and
     * sandbox) are readonly and carry no name.
     */
    public function test_the_tiktok_redirect_uri_is_shown_but_not_submittable(): void
    {
        $this->seedTiktokSetting();
        $html = $this->actingAs($this->tiktokManager())->get(route('ext.tiktok.index'))->assertOk()->getContent();

        $redirect = preg_replace('/^http:/i', 'https:', route('ext.tiktok.callback'));

        $this->assertStringContainsString(e($redirect), $html);
        $this->assertSame(2, substr_count($html, 'value="'.e($redirect).'"'));

        // Every element carrying that value is readonly and has no name.
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
        // The live half survived a sandbox save.
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

    /**
     * I-5: the form has never carried a region field (getShops() writes it),
     * so every production save used to null it out. save() now leaves it
     * alone when the form does not carry it, the same as app_key/app_secret.
     */
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

    /**
     * I-4: the shared switch (channel-settings.js) POSTs JSON on every
     * channel, and TikTokController::toggleLogging() now answers in kind
     * instead of a 302 the fetch would silently follow, re-rendering and
     * discarding the whole settings page on every flip. Mirrors Shopee's
     * and Lazada's identical assertion.
     */
    public function test_the_tiktok_logging_switch_saves_without_a_page_reload(): void
    {
        $this->seedTiktokSetting(['api_logging' => true]);

        $this->actingAs($this->tiktokManager())
            ->postJson(route('ext.tiktok.toggle_logging'), ['api_logging' => 0])
            ->assertOk()
            ->assertJson(['ok' => true, 'api_logging' => false]);

        $this->assertFalse((bool) TikTokSetting::query()->first()->api_logging);

        $this->actingAs($this->tiktokManager())
            ->postJson(route('ext.tiktok.toggle_logging'), ['api_logging' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'api_logging' => true]);

        $this->assertTrue((bool) TikTokSetting::query()->first()->api_logging);
    }

    /* =================================================================
       VENTA - store-scoped
       ================================================================= */

    public function test_every_venta_interior_renders_for_both_tiers(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);

        foreach ([$this->ventaManager(), $this->ventaViewer()] as $user) {
            foreach ($this->ventaInteriorUrls($one, $group->id) as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_no_venta_interior_still_carries_the_legacy_boundary(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaManager();

        foreach ($this->ventaInteriorUrls($one, $group->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertBoundaryDropped($html, "Venta {$label}");
        }
    }

    /**
     * The multi-store claim, stated as an assertion rather than a hope.
     *
     * Rendering store 2's pages must never emit store 1's id in a self-link,
     * and vice versa. A page that dropped the segment and fell back to "the
     * first store" would render fine and fail here.
     */
    public function test_every_venta_self_link_carries_the_store_it_arrived_on(): void
    {
        [$one, $two] = $this->seedVentaStores();
        $groupTwo = VentaProductGroup::create(['venta_setting_id' => $two, 'name' => 'Switches']);
        $user = $this->ventaManager();

        foreach ($this->ventaInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "Venta {$label}");

            // Never store 1, on any page.
            $this->assertStringNotContainsString('/integrations/venta/'.$one.'/', $content,
                "Venta {$label} for store {$two} leaked a link to store {$one}.");
            $this->assertStringNotContainsString('name="store_id" value="'.$one.'"', $content,
                "Venta {$label} for store {$two} posts store {$one}'s id.");

            // Always store 2, by whichever mechanism that page uses. The three
            // list pages put the store in the path; the settings page's forms
            // post to unscoped URLs and carry the store as a hidden field, so
            // asserting a path segment there would be asserting against a
            // mechanism it does not use.
            if ($label === 'settings') {
                $this->assertStringContainsString('name="store_id" value="'.$two.'"', $content,
                    'Venta settings does not post the store it was asked for.');
                $this->assertStringContainsString('data-store-id="'.$two.'"', $content,
                    'Venta settings does not scope its AJAX calls to the store it was asked for.');
            } else {
                $this->assertStringContainsString('/integrations/venta/'.$two.'/', $content,
                    "Venta {$label} emitted no link scoped to the store it was asked for.");
            }
        }
    }

    /**
     * The store has to be NAMED in the page body, not only in the breadcrumb.
     * Below 768px the trail collapses to its leaf, so an operator with two
     * stores open in two tabs would have nothing on screen telling them apart.
     */
    public function test_every_venta_interior_names_its_store_in_the_content(): void
    {
        [, $two] = $this->seedVentaStores();
        $groupTwo = VentaProductGroup::create(['venta_setting_id' => $two, 'name' => 'Switches']);
        $user = $this->ventaManager();

        foreach ($this->ventaInteriorUrls($two, $groupTwo->id) as $label => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $content = $this->pageContent($html, "Venta {$label}");

            $this->assertStringContainsString('Switch Bazaar', $content,
                "Venta {$label} does not name its store anywhere in the page body.");
            $this->assertStringNotContainsString('Gear Depot', $content,
                "Venta {$label} names the wrong store.");
        }
    }

    public function test_an_operator_with_no_venta_permission_is_refused_every_venta_interior(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->outsider();

        foreach ($this->ventaInteriorUrls($one, $group->id) as $label => $url) {
            $this->assertDenied($this->actingAs($user)->get($url), $label);
        }
    }

    public function test_guests_are_redirected_away_from_every_venta_interior(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);

        foreach ($this->ventaInteriorUrls($one, $group->id) as $label => $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_venta_read_tier_is_not_offered_write_controls(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaViewer();

        $groups = $this->actingAs($user)->get(route('ext.venta.product-groups.index', $one))->assertOk();
        $groups->assertDontSee(route('ext.venta.product-groups.create', $one), false);
        $groups->assertDontSee('Delete group');

        $form = $this->actingAs($user)->get(route('ext.venta.product-groups.edit', [$one, $group->id]))->assertOk();
        $form->assertDontSee('Save product group');
        $this->assertMatchesRegularExpression('/name="name"[^>]*\bdisabled\b/', $form->getContent());

        $products = $this->actingAs($user)->get(route('ext.venta.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertDontSee(route('ext.venta.product-groups.push', [$one, $group->id]), false);
        $products->assertDontSee(route('ext.venta.product-groups.addProducts', [$one, $group->id]), false);

        $settings = $this->actingAs($user)->get(route('ext.venta.settings.show', ['store' => $one]))->assertOk();
        foreach ([
            route('ext.venta.test'),
            route('ext.venta.fetch_statuses'),
            route('ext.venta.save_status_map'),
            route('ext.venta.toggle_api_logging', $one),
            route('ext.venta.clear_api_logs', $one),
        ] as $url) {
            $settings->assertDontSee($url, false);
        }

        // ext.venta.save is the exception, and deliberately so: its FORM is
        // what wraps the disabled connection fields a reader is meant to be
        // able to read. The submit control is what must be gone.
        $settings->assertDontSee('Save connection');
        $settings->assertDontSee('Test connection');
        $settings->assertDontSee('Delete store');
        // Asserted as the form action, not as a bare URL: /integrations/venta/3
        // is a PREFIX of every other store-scoped URI on this channel, so
        // assertDontSee on it alone could never pass.
        $settings->assertDontSee('action="'.e(route('ext.venta.destroy', $one)).'"', false);

        // The credential fields still render, disabled, so a reader can see
        // what is configured. The token value itself is NOT sent.
        $html = $settings->getContent();
        foreach (['store_name', 'base_url', 'api_token', 'warehouse_id', 'sync_last_days', 'sync_orders_from', 'enabled'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\bdisabled\b/',
                $html,
                "The {$field} control is editable for an operator who cannot save it."
            );
        }
        $this->assertStringNotContainsString('venta-token-one', $html,
            'The read tier was sent the store API token.');
    }

    public function test_the_venta_manage_tier_is_offered_those_same_controls(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);
        // The Delete-all-logs form only exists when there is something to
        // delete, which is the pre-redesign rule kept unchanged.
        VentaApiLog::create([
            'venta_setting_id' => $one, 'method' => 'GET', 'endpoint' => '/api/orders',
            'status_code' => 200, 'response_time_ms' => 12, 'ok' => true,
            'request_body' => null, 'response_body' => [],
        ]);
        $user = $this->ventaManager();

        $this->actingAs($user)->get(route('ext.venta.product-groups.index', $one))
            ->assertOk()->assertSee(route('ext.venta.product-groups.create', $one), false);

        $this->actingAs($user)->get(route('ext.venta.product-groups.edit', [$one, $group->id]))
            ->assertOk()->assertSee('Save product group');

        $products = $this->actingAs($user)->get(route('ext.venta.product-groups.products', [$one, $group->id]))->assertOk();
        foreach ([
            route('ext.venta.product-groups.push', [$one, $group->id]),
            route('ext.venta.product-groups.push-stock', [$one, $group->id]),
            route('ext.venta.product-groups.push-prices', [$one, $group->id]),
            route('ext.venta.product-groups.sync-ids', [$one, $group->id]),
            route('ext.venta.product-groups.mass-remove', [$one, $group->id]),
            route('ext.venta.product-groups.addProducts', [$one, $group->id]),
        ] as $url) {
            $products->assertSee($url, false);
        }

        $settings = $this->actingAs($user)->get(route('ext.venta.settings.show', ['store' => $one]))->assertOk();
        foreach ([
            route('ext.venta.save'),
            route('ext.venta.test'),
            route('ext.venta.fetch_statuses'),
            route('ext.venta.save_status_map'),
            route('ext.venta.toggle_api_logging', $one),
            route('ext.venta.clear_api_logs', $one),
        ] as $url) {
            $settings->assertSee($url, false);
        }
    }

    public function test_the_venta_write_routes_refuse_the_read_tier_on_their_own(): void
    {
        [$one] = $this->seedVentaStores();
        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => 'Pedals']);
        $user = $this->ventaViewer();

        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.save'), [
            'store_id' => $one, 'base_url' => 'https://x.test', 'api_token' => 'x',
        ]), 'save');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.venta.destroy', $one)), 'destroy');
        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.test'), ['store_id' => $one]), 'test');
        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.fetch_statuses'), ['store_id' => $one]), 'fetch_statuses');
        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.save_status_map'), ['store_id' => $one]), 'save_status_map');
        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.toggle_api_logging', $one)), 'toggle_api_logging');
        $this->assertDenied($this->actingAs($user)->delete(route('ext.venta.clear_api_logs', $one)), 'clear_api_logs');
        $this->assertDenied($this->actingAs($user)->get(route('ext.venta.product-groups.create', $one)), 'product-groups.create');
        $this->assertDenied($this->actingAs($user)->post(route('ext.venta.product-groups.push', [$one, $group->id])), 'product-groups.push');
    }

    /**
     * THE store-scoped save round trip.
     *
     * Both halves are asserted, which is the only thing that makes this claim
     * mean anything: store 1's row changed to exactly what was posted, and
     * store 2's row is byte-identical to what it was seeded with. Without the
     * second half, a save() that ignored `store_id` and wrote the first row it
     * found would pass.
     */
    public function test_a_venta_save_for_one_store_leaves_the_other_store_untouched(): void
    {
        [$one, $two] = $this->seedVentaStores();

        $before = VentaSetting::findOrFail($two);
        $beforeTwo = [
            'store_name' => $before->store_name,
            'base_url' => $before->base_url,
            'api_token' => $before->api_token,
            'enabled' => (bool) $before->enabled,
            'warehouse_id' => $before->warehouse_id,
            'sync_last_days' => $before->sync_last_days,
            'brand_color' => $before->brand_color,
        ];

        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), [
            'store_id' => $one,
            'store_name' => 'Gear Depot Renamed',
            'brand_color' => '#aabbcc',
            'base_url' => 'https://renamed.venta.test',
            'api_token' => 'venta-token-one-rotated',
            'warehouse_id' => 11,
            'sync_last_days' => 45,
            'sync_orders_from' => '2026-01-15',
            'enabled' => '1',
        ])->assertRedirect(route('ext.venta.settings.show', ['store' => $one]));

        // Every column the controller writes, read back.
        $saved = VentaSetting::findOrFail($one);
        $this->assertSame('Gear Depot Renamed', $saved->store_name);
        $this->assertSame('#aabbcc', $saved->brand_color);
        $this->assertSame('https://renamed.venta.test', $saved->base_url);
        $this->assertSame('venta-token-one-rotated', $saved->api_token);
        $this->assertSame(11, (int) $saved->warehouse_id);
        $this->assertSame(45, (int) $saved->sync_last_days);
        $this->assertSame('2026-01-15', $saved->sync_orders_from->format('Y-m-d'));
        $this->assertTrue((bool) $saved->enabled);

        // The other store, untouched.
        $after = VentaSetting::findOrFail($two);
        $this->assertSame($beforeTwo['store_name'], $after->store_name);
        $this->assertSame($beforeTwo['base_url'], $after->base_url);
        $this->assertSame($beforeTwo['api_token'], $after->api_token);
        $this->assertSame($beforeTwo['enabled'], (bool) $after->enabled);
        $this->assertSame($beforeTwo['warehouse_id'], $after->warehouse_id);
        $this->assertSame($beforeTwo['sync_last_days'], $after->sync_last_days);
        $this->assertSame($beforeTwo['brand_color'], $after->brand_color);
    }

    /**
     * I-1: the blank-keeps-stored branch. The credential field is masked in
     * the form (always sent empty), so a save that does not retype the token
     * must not wipe it - that would make every unrelated field edit
     * (renaming the store, changing the sync window) destroy the connection.
     */
    /*
     * These asserted a redirect to ext.venta.index, which encoded the defect
     * rather than the contract: that route is a legacy fallback that bounces
     * to the FIRST store by id, so saving Shop B landed the operator on
     * Shop A's settings - another live shop's credentials, with nothing
     * on screen saying it had moved. A save returns to the store it saved.
     */
    public function test_a_venta_save_with_a_blank_token_keeps_the_stored_one(): void
    {
        [$one] = $this->seedVentaStores();

        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), [
            'store_id' => $one,
            'store_name' => 'Gear Depot Renamed',
            'base_url' => 'https://one.venta.test',
            'api_token' => '',
        ])->assertRedirect(route('ext.venta.settings.show', ['store' => $one]));

        $saved = VentaSetting::findOrFail($one);
        $this->assertSame('Gear Depot Renamed', $saved->store_name, 'The rest of the form still saved.');
        $this->assertSame('venta-token-one', $saved->api_token, 'The stored token survived a blank submission.');
    }

    public function test_a_venta_save_with_a_new_token_replaces_the_stored_one(): void
    {
        [$one] = $this->seedVentaStores();

        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), [
            'store_id' => $one,
            'base_url' => 'https://one.venta.test',
            'api_token' => 'venta-token-one-replaced',
        ])->assertRedirect(route('ext.venta.settings.show', ['store' => $one]));

        $this->assertSame('venta-token-one-replaced', VentaSetting::findOrFail($one)->api_token);
    }

    /**
     * A brand new store (no store_id yet, no stored token to fall back on)
     * must still refuse to save without a token - the blank-keeps-stored
     * branch only applies once something is actually stored to keep.
     */
    public function test_a_new_venta_store_still_requires_the_api_token(): void
    {
        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), [
            'base_url' => 'https://new.venta.test',
        ])->assertSessionHasErrors('api_token');

        $this->assertSame(0, VentaSetting::query()->count());
    }

    public function test_the_venta_api_token_never_reaches_the_page_source_for_either_tier(): void
    {
        [$one] = $this->seedVentaStores();

        foreach ([$this->ventaManager(), $this->ventaViewer()] as $user) {
            $html = $this->actingAs($user)->get(route('ext.venta.settings.show', ['store' => $one]))
                ->assertOk()->getContent();

            $this->assertStringNotContainsString('venta-token-one', $html,
                'The stored API token reached the page source.');
        }
    }

    /**
     * The enabled checkbox has NO hidden companion field, deliberately.
     * VentaController::save() reads $request->has('enabled'), so a hidden 0 in
     * front of the box would make every store permanently enabled - the box
     * could never be unticked again.
     */
    public function test_the_venta_enabled_switch_can_actually_be_turned_off(): void
    {
        [$one] = $this->seedVentaStores();

        $html = $this->actingAs($this->ventaManager())
            ->get(route('ext.venta.settings.show', ['store' => $one]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="enabled" value="0"', $html,
            'A hidden companion field would make $request->has(\'enabled\') always true.');

        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), [
            'store_id' => $one,
            'base_url' => 'https://one.venta.test',
            'api_token' => 'venta-token-one',
            // `enabled` deliberately absent, which is what an unticked box posts.
        ])->assertRedirect();

        $this->assertFalse((bool) VentaSetting::findOrFail($one)->enabled);
    }

    public function test_the_venta_status_map_save_is_scoped_to_its_own_store(): void
    {
        [$one, $two] = $this->seedVentaStores();

        VentaOrderStatusMap::create([
            'venta_setting_id' => $two, 'venta_status_id' => 3,
            'venta_status_name' => 'Shipped', 'order_status_id' => 3,
        ]);

        $this->actingAs($this->ventaManager())->postJson(route('ext.venta.save_status_map'), [
            'store_id' => $one,
            'mappings' => [
                ['venta_status_id' => 3, 'venta_status_name' => 'Shipped', 'order_status_id' => 5],
            ],
        ])->assertOk();

        $this->assertSame(5, (int) VentaOrderStatusMap::where('venta_setting_id', $one)
            ->where('venta_status_id', 3)->value('order_status_id'));
        $this->assertSame(3, (int) VentaOrderStatusMap::where('venta_setting_id', $two)
            ->where('venta_status_id', 3)->value('order_status_id'));
    }

    public function test_venta_interiors_render_their_seeded_records(): void
    {
        [$one] = $this->seedVentaStores();
        $productId = $this->seedCatalogueProduct('Overdrive Pedal', 'OD-808');

        $group = VentaProductGroup::create([
            'venta_setting_id' => $one, 'name' => 'Pedal Batch',
            'markup_percent' => 5, 'markup_fixed' => 100,
        ]);
        DB::table('venta_product_group_products')->insert([
            'venta_product_group_id' => $group->id, 'product_id' => $productId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        VentaOrderStatusMap::create([
            'venta_setting_id' => $one, 'venta_status_id' => 7,
            'venta_status_name' => 'Awaiting pickup', 'order_status_id' => 0,
        ]);
        VentaApiLog::create([
            'venta_setting_id' => $one, 'method' => 'GET', 'endpoint' => '/api/orders',
            'status_code' => 200, 'response_time_ms' => 84, 'ok' => true,
            'request_body' => null, 'response_body' => ['data' => []],
        ]);
        VentaSyncLog::create([
            'venta_setting_id' => $one, 'entity_type' => 'orders', 'direction' => 'pull',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'completed_at' => now(),
            'records_processed' => 12, 'records_updated' => 4, 'records_failed' => 0,
        ]);

        $user = $this->ventaManager();

        $groups = $this->actingAs($user)->get(route('ext.venta.product-groups.index', $one))->assertOk();
        $groups->assertSee('Pedal Batch');
        $groups->assertSee('5% of price');

        $products = $this->actingAs($user)->get(route('ext.venta.product-groups.products', [$one, $group->id]))->assertOk();
        $products->assertSee('Overdrive Pedal');
        $products->assertSee('OD-808');

        $settings = $this->actingAs($user)->get(route('ext.venta.settings.show', ['store' => $one]))->assertOk();
        $settings->assertSee('Awaiting pickup');
        $settings->assertSee('/api/orders');
        // The Automations tab, not the copy-paste cron commands it replaced.
        //
        // This used to assert that the page printed `venta:sync orders
        // --store=N` for the operator to paste into a hosting panel by hand.
        // Venta's jobs are scheduled_jobs rows now, the same as every other
        // channel's, so the page shows the job and its cadence and the ERP
        // knows when it last ran.
        $settings->assertSee('Sync Orders');
        $settings->assertSee('Push Stock &amp; Price', false);
        $settings->assertSee('Push Reviews');

        // And the one requirement that lives outside the ERP is stated, or a
        // row can read "enabled, every 5 minutes" and never run once.
        $settings->assertSee('schedule:run');
    }

    public function test_venta_channel_sourced_names_are_escaped(): void
    {
        [$one] = $this->seedVentaStores();
        $payload = '<img src=x onerror=alert(1)>';

        $group = VentaProductGroup::create(['venta_setting_id' => $one, 'name' => $payload]);
        VentaOrderStatusMap::create([
            'venta_setting_id' => $one, 'venta_status_id' => 9,
            'venta_status_name' => $payload, 'order_status_id' => 0,
        ]);
        VentaApiLog::create([
            'venta_setting_id' => $one, 'method' => 'GET', 'endpoint' => $payload,
            'status_code' => 500, 'response_time_ms' => 3, 'ok' => false,
            'request_body' => null, 'response_body' => ['error' => $payload],
        ]);

        $user = $this->ventaManager();

        foreach ([
            route('ext.venta.product-groups.index', $one),
            route('ext.venta.product-groups.edit', [$one, $group->id]),
            route('ext.venta.settings.show', ['store' => $one]),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=', $html, "Unescaped output on {$url}");
        }
    }

    /* =================================================================
       OPENCART - store-scoped
       ================================================================= */

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

            $this->assertStringNotContainsString('/integrations/opencart/'.$one.'/', $content,
                "OpenCart {$label} for store {$two} leaked a link to store {$one}.");
            $this->assertStringNotContainsString('name="store_id" value="'.$one.'"', $content,
                "OpenCart {$label} for store {$two} posts store {$one}'s id.");

            // Same split as Venta: OpenCart's settings forms all post to
            // unscoped URLs and carry the store as a hidden field.
            if ($label === 'settings') {
                $this->assertStringContainsString('name="store_id" value="'.$two.'"', $content,
                    'OpenCart settings does not post the store it was asked for.');
                $this->assertStringContainsString('data-store-id="'.$two.'"', $content,
                    'OpenCart settings does not scope its AJAX calls to the store it was asked for.');
                $this->assertStringContainsString('--store='.$two, $content,
                    'OpenCart settings printed a cron command for the wrong store.');
            } else {
                $this->assertStringContainsString('/integrations/opencart/'.$two.'/', $content,
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

        // ext.opencart.sync has the same prefix hazard the destroy route has:
        // /integrations/opencart/sync is a PREFIX of
        // /integrations/opencart/sync-date, whose form legitimately renders for
        // a reader around the disabled window fields. Asserted as the attribute
        // the module actually reads, which is empty for a reader.
        $settings->assertSee('data-sync-url=""', false);
        $settings->assertSee('data-push-url=""', false);
        $settings->assertSee('data-verify-url=""', false);
        $settings->assertSee('data-test-url=""', false);

        // Three saves keep their FORMS, because those forms are what wrap the
        // disabled fields a reader is meant to be able to read. Their submit
        // controls are what must be gone.
        $settings->assertDontSee('Save connection');
        $settings->assertDontSee('Save the window');
        $settings->assertDontSee('Save review setting');
        $settings->assertDontSee('Test connection');
        $settings->assertDontSee('Delete store');
        $settings->assertDontSee('action="'.e(route('ext.opencart.destroy', $one)).'"', false);
        // The whole first-import tab is manage-only, tab strip included.
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
        $settings->assertSee('action="'.e(route('ext.opencart.destroy', $one)).'"', false);
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

    /** The OpenCart half of the store-scoped save claim. Both stores asserted. */
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

    /**
     * I-1: the blank-keeps-stored branch, OpenCart side. Mirrors Venta's.
     */
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

    /**
     * Same reasoning as Venta's: saveReviewSettings() and save() both read
     * $request->has(), so neither switch may carry a hidden companion field.
     */
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
        $productId = $this->seedCatalogueProduct('Patch Cable', 'PC-15');

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
        $settings->assertSee('opencart:sync orders --store='.$one);
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

    /* =================================================================
       CROSS-CHANNEL
       ================================================================= */

    /**
     * Holding one channel's manage tier opens nothing on the others. The
     * cheapest way for a gate to be wrong is for it to be checked against the
     * wrong channel's permission key.
     *
     * I-3: this matrix originally covered only the TikTok/Venta/OpenCart
     * triangle; test_the_other_channels_manage_tier_opens_nothing_here()
     * separately covers Shopee<->Lazada. Neither combination ever put
     * Shopee's manager against TikTok/Venta/OpenCart (or the reverse), so a
     * route gate that accidentally accepted `manage_tiktok` alongside
     * `manage_shopee` on a Shopee read group could survive the whole suite.
     * Shopee is folded into this matrix to close that gap.
     */
    public function test_one_channels_manage_tier_opens_nothing_on_the_others(): void
    {
        $this->seedSetting();
        $this->seedTiktokSetting();
        [$ventaStore] = $this->seedVentaStores();
        [$opencartStore] = $this->seedOpencartStores();

        $ventaGroup = VentaProductGroup::create(['venta_setting_id' => $ventaStore, 'name' => 'Pedals']);
        $opencartGroup = OpenCartProductGroup::create(['opencart_setting_id' => $opencartStore, 'name' => 'Cables']);

        $shopeeUrls = $this->interiorUrls();
        $tiktokUrls = $this->tiktokInteriorUrls();
        $ventaUrls = $this->ventaInteriorUrls($ventaStore, $ventaGroup->id);
        $opencartUrls = $this->opencartInteriorUrls($opencartStore, $opencartGroup->id);

        $matrix = [
            'shopee manager' => [$this->manager(), array_merge($tiktokUrls, $ventaUrls, $opencartUrls)],
            'tiktok manager' => [$this->tiktokManager(), array_merge($shopeeUrls, $ventaUrls, $opencartUrls)],
            'venta manager' => [$this->ventaManager(), array_merge($shopeeUrls, $tiktokUrls, $opencartUrls)],
            'opencart manager' => [$this->opencartManager(), array_merge($shopeeUrls, $tiktokUrls, $ventaUrls)],
        ];

        foreach ($matrix as $who => [$user, $urls]) {
            foreach ($urls as $label => $url) {
                $this->assertDenied($this->actingAs($user)->get($url), "{$who} reached {$label}");
            }
        }
    }

    /**
     * ONE GATE, PROVEN.
     *
     * /venta/save is the riskiest write on this task's eleven pages: it is
     * store-scoped, it rotates a credential, and it is reachable from a page a
     * read-tier operator can open. This test is the one the mutation proof was
     * run against - relaxing the route's own middleware to accept either tier
     * must make the viewer half of this fail.
     */
    public function test_the_venta_save_gate_separates_the_two_tiers_on_one_route(): void
    {
        [$one] = $this->seedVentaStores();

        $payload = [
            'store_id' => $one,
            'store_name' => 'Renamed By Test',
            'base_url' => 'https://gate.venta.test',
            'api_token' => 'gate-token',
        ];

        // The read tier is refused, and nothing moved.
        $this->assertDenied(
            $this->actingAs($this->ventaViewer())->post(route('ext.venta.save'), $payload),
            'venta save, viewer'
        );
        $this->assertSame('Gear Depot', VentaSetting::findOrFail($one)->store_name);

        // The manage tier goes through, and the row really changed.
        $this->actingAs($this->ventaManager())->post(route('ext.venta.save'), $payload)->assertRedirect();
        $this->assertSame('Renamed By Test', VentaSetting::findOrFail($one)->store_name);
    }

    /* =================================================================
       LAYOUT BANNERS ARE SCOPED TO THE CHANNEL YOU ARE STANDING IN
       ================================================================= */

    /**
     * A channel that contributes no banners of its own must show none, not
     * every other channel's.
     *
     * partials/legacy-page-contract.blade.php decides which banners belong on
     * a page by asking which channel the page is in. It used to answer that
     * from the map of banner CONTRIBUTORS, so a channel that contributes no
     * banners - Venta, OpenCart, Shopify, Pedallion, and any integration
     * added tomorrow, since the contributor interface is opt-in - looked
     * exactly like "not in a channel at all" and fell through to showing
     * everybody's. The owner found TikTok's expired-token alert sitting on top of
     * Venta and OpenCart pages.
     *
     * This is pinned per channel rather than as one loop so a failure names
     * the channel that regressed.
     */
    public function test_a_channel_that_contributes_no_banners_shows_no_other_channels_banners(): void
    {
        // An expired TikTok token, which is the one banner in play here.
        $this->seedTiktokSetting(['expires_at' => now()->subWeeks(2)]);

        [$ventaStore] = $this->seedVentaStores();
        $ventaGroup = VentaProductGroup::create(['venta_setting_id' => $ventaStore, 'name' => 'Pedals']);

        foreach ($this->ventaInteriorUrls($ventaStore, $ventaGroup->id) as $label => $url) {
            $this->actingAs($this->ventaManager())->get($url)->assertOk()
                ->assertDontSee('TikTok Shop: Access token expired.', false);
        }

        [$ocStore] = $this->seedOpencartStores();
        $ocGroup = OpenCartProductGroup::create(['opencart_setting_id' => $ocStore, 'name' => 'Pedals']);

        foreach ($this->opencartInteriorUrls($ocStore, $ocGroup->id) as $label => $url) {
            $this->actingAs($this->opencartManager())->get($url)->assertOk()
                ->assertDontSee('TikTok Shop: Access token expired.', false);
        }
    }

    /**
     * The other half of the same rule, so a fix cannot pass by suppressing
     * every banner everywhere: the channel that OWNS the warning still shows
     * it on its own pages.
     */
    public function test_a_channel_still_shows_its_own_banner(): void
    {
        $this->seedTiktokSetting(['expires_at' => now()->subWeeks(2)]);

        $this->actingAs($this->tiktokManager())
            ->get(route('ext.tiktok.index'))
            ->assertOk()
            ->assertSee('TikTok Shop: Access token expired.', false);
    }
}
