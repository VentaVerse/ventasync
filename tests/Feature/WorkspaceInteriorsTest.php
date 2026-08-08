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
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->app->register(ShopeeExtension::class);

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
     * The point of the whole task. `.x-legacy` is applied by
     * layouts/channel.blade.php as the DEFAULT of @yield('content-class'),
     * so a page that has not opted out carries it on its <main>. Each of
     * these five now sets @section('content-class', ''), and this asserts
     * the rendered <main> really lost the class rather than the section
     * merely being present in the file.
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

        $user = $this->manager();

        foreach ([
            route('ext.shopee.categories.index'),
            route('ext.shopee.logistics.index'),
            route('ext.shopee.product-groups.index'),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
            $this->assertStringNotContainsString('<b>bold group</b>', $html);
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
    public function test_the_redirect_uri_is_shown_but_not_submittable(): void
    {
        $this->seedSetting();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.shopee.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(route('ext.shopee.index')), $html);
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
}
