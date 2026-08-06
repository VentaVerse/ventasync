<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaletteSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/palette/search?q=test')->assertUnauthorized();
    }

    public function test_short_queries_return_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/palette/search?q=a')
            ->assertOk()
            ->assertJson(['orders' => [], 'products' => []]);
    }

    public function test_finds_an_order_by_id(): void
    {
        $p = (string) config('catalog.prefix');

        // The legacy OpenCart-derived `order` table has many NOT NULL columns
        // with no default; MySQL's default STRICT_TRANS_TABLES mode rejects
        // an insert that omits them. Column set copied from the working
        // makeOrder() helper in NormalizeOrderCurrencyTest.
        \DB::table($p.'order')->insert([
            'order_id' => 990001, 'currency_code' => 'PHP', 'currency_value' => 1,
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

        $this->actingAs(User::factory()->create())
            ->getJson('/palette/search?q=990001')
            ->assertOk()
            ->assertJsonFragment(['id' => 990001]);
    }
}
