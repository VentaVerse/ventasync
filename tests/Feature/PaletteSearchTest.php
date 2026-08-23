<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaletteSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same pattern OrderControllerCurrencyTest uses: build a user group that
     * carries manage_orders and act as a user in it, so hasPermission()'s
     * manage-implies-view cascade satisfies the door's sales/order view
     * tier.
     */
    private function actingAsOrderViewer(): User
    {
        $group = UserGroup::create(['name' => 'Order Viewers']);
        $permission = Permission::where('key', 'manage_sales/order')->firstOrFail();
        $group->permissions()->attach($permission->id);

        $user = User::factory()->create(['user_group_id' => $group->id]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * The legacy OpenCart-derived `order` table has many NOT NULL columns
     * with no default; MySQL's default STRICT_TRANS_TABLES mode rejects an
     * insert that omits them. Column set copied from the working makeOrder()
     * helper in NormalizeOrderCurrencyTest.
     */
    private function seedOrder(int $orderId): void
    {
        $p = (string) config('catalog.prefix');

        \DB::table($p.'order')->insert([
            'order_id' => $orderId, 'currency_code' => 'PHP', 'currency_value' => 1,
            'total' => 100, 'order_status_id' => 1, 'date_added' => '2021-04-19 00:00:00',
            'date_modified' => '2021-04-19 00:00:00',
            'invoice_prefix' => 'INV-', 'store_name' => '', 'store_url' => '',
            'firstname' => 'Palette', 'lastname' => 'Probe', 'email' => '', 'telephone' => '', 'fax' => '',
            'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '',
            'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0,
            'payment_zone' => '', 'payment_zone_id' => 0,
            'payment_address_format' => '', 'payment_custom_field' => '',
            'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '',
            'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0,
            'shipping_zone' => '', 'shipping_zone_id' => 0,
            'shipping_address_format' => '', 'shipping_custom_field' => '',
            'shipping_method' => '', 'shipping_code' => '',
            'comment' => '', 'affiliate_id' => 0, 'commission' => 0,
            'marketing_id' => 0, 'tracking' => '', 'language_id' => 0, 'currency_id' => 0,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
        ]);
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/palette/search?q=test')->assertUnauthorized();
    }

    public function test_short_queries_return_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/palette/search?q=a')
            ->assertOk()
            ->assertJsonCount(0, 'orders')
            ->assertJsonCount(0, 'products');
    }

    /**
     * A user holding view_orders|manage_orders gets order results. Gated by
     * commit d6fc085e; before that fix every authenticated user, permitted
     * or not, got results here.
     */
    public function test_a_user_with_view_orders_finds_an_order_by_id(): void
    {
        $this->seedOrder(990001);
        $this->actingAsOrderViewer();

        $this->getJson('/palette/search?q=990001')
            ->assertOk()
            ->assertJsonFragment(['id' => 990001]);
    }

    /**
     * The other direction of the same gate, and the one a broken "always
     * deny" implementation would also pass if this were the only test: a
     * user with NO order permission searching for an order that genuinely
     * exists gets an empty result, not a 403/500 and not a silent partial
     * list. Proves the endpoint is filtering by permission, not merely
     * failing to find data.
     */
    public function test_a_user_without_view_orders_finds_nothing(): void
    {
        $this->seedOrder(990002);

        $this->actingAs(User::factory()->create())
            ->getJson('/palette/search?q=990002')
            ->assertOk()
            ->assertJsonCount(0, 'orders')
            ->assertJsonCount(0, 'products');
    }
}
