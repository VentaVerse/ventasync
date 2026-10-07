<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Support\Breadcrumbs;
use App\Support\CatalogImages;
use App\Support\ChannelWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LiveFixesAugust26Test extends TestCase
{
    use RefreshDatabase;

    private int $groupSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    public function test_a_variation_sku_falls_back_to_the_product_picture(): void
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'LF-1', 'sku' => 'LF', 'quantity' => 10, 'price' => 100,
            'status' => 1, 'image' => 'catalog/parent.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table('product_option_combinations')->insert([
            ['product_id' => $productId, 'sku' => 'LF-RED', 'quantity' => 1, 'absolute_price' => 100, 'image' => 'catalog/red.png', 'status' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $productId, 'sku' => 'LF-BLUE', 'quantity' => 1, 'absolute_price' => 100, 'image' => null, 'status' => 1, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $images = CatalogImages::forSkus(['LF-RED', 'LF-BLUE', 'LF', 'LF-1', 'NOPE']);

        $this->assertSame('catalog/red.png', $images['LF-RED'], 'a variation with its own picture keeps it');
        $this->assertSame('catalog/parent.png', $images['LF-BLUE'], 'a variation without one shows the product');
        $this->assertSame('catalog/parent.png', $images['LF'], 'the product SKU itself');
        $this->assertSame('catalog/parent.png', $images['LF-1'], 'the model too');
        $this->assertArrayNotHasKey('NOPE', $images, 'an unknown SKU resolves to nothing, never a guess');
        $this->assertStringEndsWith('storage/catalog/parent.png', (string) CatalogImages::urlFor('LF-BLUE'));
        $this->assertNull(CatalogImages::urlFor(''));
    }

    public function test_the_sign_in_expiry_reads_the_refresh_token_not_the_four_hour_access_token(): void
    {
        $row = (object) [
            'access_token' => 't', 'refresh_token' => 'r',
            'expires_at' => now()->addHours(3)->toDateTimeString(),
            'refresh_expires_at' => now()->addDays(29)->toDateTimeString(),
        ];
        $this->assertEqualsWithDelta(29, now()->diffInDays(ChannelWorkspace::signInExpiry($row, false)), 0.01);

        $row->refresh_expires_at = null;
        $this->assertNull(ChannelWorkspace::signInExpiry($row, false));

        $row->refresh_token = null;
        $this->assertEqualsWithDelta(3, now()->diffInHours(ChannelWorkspace::signInExpiry($row, false)), 0.01);

        $sandbox = (object) ['sandbox_refresh_token' => 'r', 'sandbox_refresh_expires_at' => now()->addDays(10)->toDateTimeString(), 'sandbox_expires_at' => now()->addHours(1)->toDateTimeString()];
        $this->assertEqualsWithDelta(10, now()->diffInDays(ChannelWorkspace::signInExpiry($sandbox, true)), 0.01);

        $this->assertNull(ChannelWorkspace::signInExpiry(null, false));
    }

    public function test_a_healthy_shopee_shop_is_connected_not_expiring(): void
    {
        DB::table('shopee_settings')->insert([
            'partner_id' => 1, 'partner_key' => encrypt('k'), 'shop_id' => 2,
            'access_token' => encrypt('t'), 'refresh_token' => encrypt('r'), 'mode' => 'production',
            'expires_at' => now()->addHours(2), 'refresh_expires_at' => now()->addDays(25),
            'last_order_sync_at' => now()->subMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $card = null;
        foreach (app(\App\Integrations\IntegrationRegistry::class)->all() as $provider) {
            foreach ($provider->integrationCards() as $c) {
                if ($c->id === 'shopee') {
                    $card = $c;
                }
            }
        }
        $this->assertNotNull($card, 'Shopee did not register an IntegrationCard.');
        $resolved = app(ChannelWorkspace::class)->stateWithReason($card, 'shopee');

        $this->assertSame(['state' => 'connected', 'reason' => null], $resolved);
    }

    public function test_shopee_stamps_the_refresh_token_life_at_each_grant(): void
    {
        $this->assertSame(30, \Extensions\shopee\Models\ShopeeSetting::REFRESH_TOKEN_DAYS);
        foreach (['extensions/shopee/Controllers/ShopeeController.php', 'extensions/shopee/Commands/ShopeeRefreshToken.php'] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertNotFalse($src, $file);
            $this->assertStringContainsString('refresh_expires_at = now()->addDays(ShopeeSetting::REFRESH_TOKEN_DAYS)', $src, $file);
        }
    }

    public function test_the_settings_key_saves_an_automation_schedule(): void
    {
        $job = ScheduledJob::create([
            'command' => 'shopee:sync-orders', 'display_name' => 'Sync orders', 'integration' => 'shopee',
            'cadence_value' => 15, 'cadence_unit' => 'minute', 'enabled' => true,
        ]);

        $manager = $this->userWith(['manage_shopee/settings']);
        $this->actingAs($manager)
            ->post(route('automations.update', $job->id), ['cadence_value' => 2, 'cadence_unit' => 'hour', 'enabled' => '1'])
            ->assertSessionMissing('error');
        $this->assertSame(2, (int) $job->fresh()->cadence_value);

        $this->actingAs($manager)
            ->post(route('automations.batch_update'), ['jobs' => [$job->id => ['cadence_value' => 3, 'cadence_unit' => 'hour']]])
            ->assertSessionMissing('error');

        $viewer = $this->userWith(['view_shopee/settings']);
        $this->actingAs($viewer)
            ->post(route('automations.update', $job->id), ['cadence_value' => 9, 'cadence_unit' => 'hour'])
            ->assertSessionHas('error');
        $this->assertNotSame(9, (int) $job->fresh()->cadence_value);
    }

    public function test_order_payments_carry_their_own_keys_and_the_administrator_holds_them(): void
    {
        $this->assertTrue(Permission::where('key', 'view_sales/order_payment')->exists());
        $this->assertTrue(Permission::where('key', 'manage_sales/order_payment')->exists());

        $admin = UserGroup::firstOrCreate(['name' => 'Administrator']);
        $this->artisan('permissions:sync-catalogue');
        $this->assertTrue($admin->permissions()->where('key', 'manage_sales/order_payment')->exists(),
            'a key that grows out of a route is granted to the Administrator group as it appears');

        $orderOnly = $this->userWith(['view_sales/order', 'manage_sales/order']);
        $this->actingAs($orderOnly)->get(route('orders.payments_report'))->assertForbidden();

        $books = $this->userWith(['view_sales/order', 'view_sales/order_payment']);
        $this->actingAs($books)->get(route('orders.payments_report'))->assertOk();
    }

    public function test_settings_pages_carry_a_trail_back_to_settings(): void
    {
        $this->assertSame(
            [['label' => 'Settings', 'url' => route('settings.hub')], ['label' => 'Users', 'url' => route('users.index')]],
            Breadcrumbs::for(Request::create('/settings/users/4/edit', 'GET'))
        );
        $this->assertSame(
            [['label' => 'Settings', 'url' => route('settings.hub')]],
            Breadcrumbs::for(Request::create('/settings/users', 'GET')),
            'the card itself is the leaf the page supplies, not printed twice'
        );

        $layout = file_get_contents(resource_path('views/layouts/blotter.blade.php'));
        $this->assertNotFalse($layout);
        $this->assertStringContainsString("@include('partials.breadcrumb')", $layout);

        $admin = $this->userWith(['manage_settings/user']);
        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('x-crumb__link', $html);
        $this->assertStringContainsString(route('settings.hub'), $html);
    }

    private function userWith(array $keys): User
    {
        foreach ($keys as $k) {
            Permission::firstOrCreate(['key' => $k]);
        }
        $group = UserGroup::create(['name' => 'Live fix group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_run_now_runs_the_job_by_hand_and_stamps_the_row(): void
    {
        $job = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Say something', 'command' => 'inspire',
            'cadence_value' => 1, 'cadence_unit' => 'hour', 'enabled' => false, 'options' => [],
        ]);
        $this->assertNull($job->last_run_at);

        $manager = $this->userWith(['manage_shopee/settings']);
        $this->actingAs($manager)->from('/channels/shopee/1?tab=automations')
            ->post(route('automations.run', $job->id))
            ->assertRedirect('/channels/shopee/1?tab=automations')
            ->assertSessionHas('status');
        $this->assertStringStartsWith('Ran Say something.', (string) session('status'));
        $job->refresh();
        $this->assertNotNull($job->last_run_at);
        $this->assertTrue($job->last_run_ok);

        $broken = \App\Models\ScheduledJob::create([
            'integration' => 'shopee', 'display_name' => 'Broken job', 'command' => 'no:such-command',
            'cadence_value' => 1, 'cadence_unit' => 'hour', 'enabled' => false, 'options' => [],
        ]);
        $this->actingAs($manager)->post(route('automations.run', $broken->id))->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($broken->fresh()->last_run_ok);

        $this->actingAs($this->userWith(['view_shopee/settings']))->post(route('automations.run', $job->id))->assertRedirect()->assertSessionHas('error');
    }
}
