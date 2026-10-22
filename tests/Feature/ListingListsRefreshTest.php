<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeListingTemplate;
use Extensions\Shopee\Models\ShopeeLogistic;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeStoreSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingListsRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        ShopeeStore::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);
    }

    private function user(array $keys = ['view_shopee/listing_template', 'manage_shopee/listing_template']): User
    {
        $group = UserGroup::create(['name' => 'Lists ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function courier(int $id, string $name, bool $enabled, bool $forced = false): void
    {
        ShopeeLogistic::query()->create([
            'store_id' => (int) ShopeeStore::defaultStore()->id, 'logistics_channel_id' => $id, 'logistics_channel_name' => $name,
            'enabled' => $enabled, 'force_enable' => $forced,
        ]);
    }

    public function test_the_template_lists_every_courier_and_labels_the_ones_switched_off(): void
    {
        $this->courier(1, 'SPX Express', true);
        $this->courier(2, 'Flash Express', false);
        $this->courier(3, 'Instant Delivery', false, true);
        $template = ShopeeListingTemplate::create(['store_id' => (int) ShopeeStore::defaultStore()->id, 'name' => 'Pedals']);

        $html = $this->actingAs($this->user())->get(route('ext.shopee.listing-templates.edit', ['template' => $template->id]))
            ->assertOk()->assertSee('Only on Shopee')->assertSee('Couriers')->assertDontSee('Delivery options')
            ->getContent();

        $this->assertMatchesRegularExpression('~Flash Express</span>\s*<span class="gf-check__id">2</span>\s*<span class="gf-check__off">Off in Seller Centre</span>~', $html);
        $this->assertDoesNotMatchRegularExpression('~SPX Express</span>\s*<span class="gf-check__id">1</span>\s*<span class="gf-check__off">~', $html);
        $this->assertDoesNotMatchRegularExpression('~Instant Delivery</span>\s*<span class="gf-check__id">3</span>\s*<span class="gf-check__off">~', $html, 'Shopee keeps a forced courier on');
        $this->assertStringContainsString('data-list-refresh="' . route('ext.shopee.listing-templates.refresh_list', ['list' => 'couriers']) . '"', $html);
        $this->assertStringContainsString('data-refresh-url="' . route('ext.shopee.listing-templates.refresh_list', ['list' => 'categories']) . '"', $html);
    }

    public function test_refresh_answers_with_the_couriers_and_their_state(): void
    {
        $this->courier(2, 'Flash Express', false);
        $this->app->instance(ShopeeStoreSetup::class, new class extends ShopeeStoreSetup {
            public function __construct() {}
            public function couriers(array $auth): array
            {
                return ['ok' => true, 'count' => 1, 'message' => '1 courier fetched.'];
            }
        });

        $this->actingAs($this->user())->postJson(route('ext.shopee.listing-templates.refresh_list', ['list' => 'couriers']))
            ->assertOk()
            ->assertJsonPath('message', '1 courier fetched.')
            ->assertJsonPath('items.0.logistic_name', 'Flash Express')
            ->assertJsonPath('items.0.enabled', false);
    }

    public function test_refresh_refuses_a_list_the_channel_does_not_have_and_a_reader(): void
    {
        $this->actingAs($this->user())->postJson(str_replace('/lists/couriers', '/lists/brands', route('ext.shopee.listing-templates.refresh_list', ['list' => 'couriers'])))
            ->assertClientError();
        $called = false;
        $this->app->instance(ShopeeStoreSetup::class, new class($called) extends ShopeeStoreSetup {
            public function __construct(private bool &$called) {}
            public function couriers(array $auth): array
            {
                $this->called = true;

                return ['ok' => true, 'count' => 0, 'message' => ''];
            }
        });
        $this->actingAs($this->user(['view_shopee/listing_template']))->postJson(route('ext.shopee.listing-templates.refresh_list', ['list' => 'couriers']))
            ->assertStatus(403);
        $this->assertFalse($called, 'a reader never reaches Shopee');
    }
}
