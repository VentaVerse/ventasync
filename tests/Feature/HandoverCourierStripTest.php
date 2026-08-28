<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaOrderProduct;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Riders arrive one courier at a time and in no fixed order, so the courier
 * split on To Handover is a filter, not a caption: tap J&T, see J&T's
 * parcels, oldest first. And the seconds a marketplace takes to answer a
 * pack or a ship are narrated on the page, the way Shopee's ship already is.
 */
class HandoverCourierStripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same idempotent-install rationale as FulfilmentHubTest::setUp().
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['lazada', 'tiktok'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }

        $this->app->register(LazadaExtension::class);
        $this->app->register(TiktokExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function userWith(array $permissionKeys): User
    {
        $group = UserGroup::create(['name' => 'Handover '.implode('-', $permissionKeys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function lazadaHandoverOrder(string $ref, string $courier, string $placedAt): LazadaOrder
    {
        $order = LazadaOrder::create([
            'region' => 'ph',
            'order_id' => $ref,
            'status' => 'ready_to_ship',
            'order_created_at' => $placedAt,
            'raw' => [
                'order_number' => $ref,
                'customer_first_name' => 'Fixture Buyer',
                'price' => '100.00',
                'currency' => 'PHP',
            ],
        ]);

        LazadaOrderProduct::create([
            'lazada_order_id' => $order->id,
            'order_item_id' => 'ITEM-'.$ref,
            'name' => 'Fixture item '.$ref,
            'sku' => 'SKU-'.$ref,
            'quantity' => 1,
            'raw' => ['shipment_provider' => $courier],
        ]);

        return $order;
    }

    public function test_the_handover_step_offers_one_pill_per_courier_with_names_a_packer_reads(): void
    {
        $this->lazadaHandoverOrder('JT-1', 'Pickup: J&T Express PH, Delivery: J&T Express PH', '2026-08-01 09:00:00');
        $this->lazadaHandoverOrder('JT-2', 'Pickup: J&T Express PH, Delivery: J&T Express PH', '2026-08-02 09:00:00');
        $this->lazadaHandoverOrder('FL-1', 'Pickup: Flash Express PH, Delivery: Flash Express PH', '2026-08-03 09:00:00');

        $html = $this->actingAs($this->userWith(['view_lazada/order']))
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_handover')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Hand over to"', $html);
        $this->assertStringContainsString('All couriers', $html);
        $this->assertStringContainsString('>J&amp;T Express<', $html);
        $this->assertStringContainsString('>Flash Express<', $html);
        // The raw two-leg string never reaches the strip (the row's own
        // Shipping column still prints it, and is not what is asserted).
        preg_match('/<nav class="x-segment x-segment--sub x-segment--courier".*?<\/nav>/s', $html, $strip);
        $this->assertNotEmpty($strip);
        $this->assertStringNotContainsString('Pickup:', $strip[0]);
        $this->assertStringNotContainsString(' PH<', $strip[0]);
        $this->assertStringNotContainsString('Parcels waiting for handover', $html);
    }

    public function test_a_courier_pill_narrows_the_list_to_that_couriers_parcels(): void
    {
        $this->lazadaHandoverOrder('JT-1', 'Pickup: J&T Express PH, Delivery: J&T Express PH', '2026-08-01 09:00:00');
        $this->lazadaHandoverOrder('FL-1', 'Pickup: Flash Express PH, Delivery: Flash Express PH', '2026-08-03 09:00:00');

        $html = $this->actingAs($this->userWith(['view_lazada/order']))
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_handover&courier='.urlencode('flash express'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('FL-1', $html);
        $this->assertStringNotContainsString('JT-1', $html);

        // The strip still shows every courier, with the chosen one current,
        // so the way back is as visible as the way in.
        $this->assertMatchesRegularExpression('/is-active"[^>]*aria-current="true"[^>]*>\s*<span>Flash Express<\/span>/', $html);
        $this->assertStringContainsString('>J&amp;T Express<', $html);
    }

    public function test_a_stale_courier_key_leaves_the_list_whole(): void
    {
        $this->lazadaHandoverOrder('JT-1', 'Pickup: J&T Express PH, Delivery: J&T Express PH', '2026-08-01 09:00:00');

        $html = $this->actingAs($this->userWith(['view_lazada/order']))
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_handover&courier=spx+express')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('JT-1', $html);
    }

    public function test_the_courier_filter_does_not_leak_onto_other_steps(): void
    {
        $this->lazadaHandoverOrder('JT-1', 'Pickup: J&T Express PH, Delivery: J&T Express PH', '2026-08-01 09:00:00');
        LazadaOrder::create([
            'region' => 'ph',
            'order_id' => 'PK-1',
            'status' => 'pending',
            'order_created_at' => '2026-08-01 09:00:00',
            'raw' => ['order_number' => 'PK-1', 'price' => '100.00', 'currency' => 'PHP'],
        ]);

        $html = $this->actingAs($this->userWith(['view_lazada/order']))
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_pack&courier=flash+express')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PK-1', $html);
        $this->assertStringNotContainsString('aria-label="Hand over to"', $html);
    }

    public function test_lazadas_marketplace_writes_name_their_wait(): void
    {
        LazadaOrder::create([
            'region' => 'ph',
            'order_id' => 'PK-1',
            'status' => 'pending',
            'order_created_at' => '2026-08-01 09:00:00',
            'raw' => ['order_number' => 'PK-1', 'price' => '100.00', 'currency' => 'PHP'],
        ]);

        $html = $this->actingAs($this->userWith(['view_lazada/order', 'manage_lazada/order']))
            ->get('/channels/lazada/orders?tab=TO_SHIP&pending_sub=to_pack')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-busy="Packing this order on Lazada"', $html);
        $this->assertStringContainsString('data-busy-count="Packing :n orders on Lazada"', $html);
        $this->assertStringContainsString('id="lzLoadingOverlay" class="modal-backdrop" data-busy-overlay', $html);
        $this->assertStringContainsString('data-busy-title', $html);
    }

    public function test_tiktoks_ship_names_its_wait(): void
    {
        TikTokOrder::create([
            'order_id' => 'TT-1',
            'status' => 'AWAITING_SHIPMENT',
            'order_created_at' => '2026-08-01 09:00:00',
            'raw' => ['id' => 'TT-1'],
        ]);

        $html = $this->actingAs($this->userWith(['view_tiktok/order', 'manage_tiktok/order']))
            ->get('/channels/tiktok/orders?tab=TO_SHIP&pending_sub=to_pack')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-busy="Marking shipped on TikTok Shop"', $html);
        $this->assertStringContainsString('id="ttLoadingOverlay" class="modal-backdrop" data-busy-overlay', $html);
    }

    public function test_the_busy_overlay_module_is_bundled_after_the_confirm_dialog(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($app);

        $confirmAt = strpos($app, "import './confirm-modal';");
        $busyAt = strpos($app, "import './busy-overlay';");

        $this->assertNotFalse($confirmAt);
        $this->assertNotFalse($busyAt);
        // Order is not load-bearing (both listen on document), but keeping
        // them adjacent is what keeps the contract between them findable.
        $this->assertGreaterThan($confirmAt, $busyAt);
    }
}
