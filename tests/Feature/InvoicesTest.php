<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\User;
use App\Services\PermissionCatalog;
use App\Models\Invoice;
use App\Models\InvoiceSettings;
use App\Models\TaxRate;
use App\Services\Sales\InvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $keys): User
    {
        $this->artisan('permissions:sync-catalog');
        $group = UserGroup::create(['name' => 'Group ' . md5(implode(',', $keys))]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function order(float $total = 1660, array $overrides = []): Order
    {
        $status = OrderStatus::firstOrCreate(['name' => 'Shipped'], ['language_id' => 1, 'subtract_stock' => 0, 'add_revenue' => 0]);

        $order = Order::create(array_merge([
            'store_id' => 0, 'store_name' => '', 'firstname' => 'Maria', 'lastname' => 'Santos', 'payment_method' => 'Shopee Pay', 'shipping_method' => '',
            'courier_id' => 0, 'tracking_number' => '', 'email' => '', 'telephone' => '',
            'payment_firstname' => 'Maria', 'payment_lastname' => 'Santos', 'payment_company' => '',
            'payment_address_1' => '22 Mabini Street', 'payment_address_2' => '', 'payment_city' => 'Marikina City', 'payment_postcode' => '1801',
            'payment_country' => 'Philippines', 'payment_zone' => 'Metro Manila', 'payment_cost' => 0,
            'shipping_firstname' => 'Maria', 'shipping_lastname' => 'Santos', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '', 'shipping_postcode' => '',
            'shipping_country' => '', 'shipping_zone' => '', 'shipping_cost' => 120,
            'customer_note' => '', 'total' => $total, 'foreign_total' => null, 'extra_cost' => 0, 'order_status_id' => $status->order_status_id,
            'currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1, 'ip' => '',
            'date_added' => now(), 'date_modified' => now(), 'track_payments' => true,
        ], $overrides));

        DB::table('order_products')->insert(['order_id' => $order->order_id, 'product_id' => 1, 'name' => 'Mogami W2314 Microphone Cable', 'sku' => 'MOG-2314-10',
            'quantity' => 2, 'price' => 400, 'total' => 800, 'cost' => 200]);
        DB::table('order_totals')->insert([
            ['order_id' => $order->order_id, 'code' => 'sub_total', 'title' => 'Subtotal', 'value' => $total - 120, 'sort_order' => 1],
            ['order_id' => $order->order_id, 'code' => 'shipping', 'title' => 'Shipping', 'value' => 120, 'sort_order' => 2],
            ['order_id' => $order->order_id, 'code' => 'total', 'title' => 'Total', 'value' => $total, 'sort_order' => 9],
        ]);

        return $order;
    }

    private function document(Order $order): string
    {
        return view('sales.orders.pdf', app(InvoiceDocument::class)->for($order))->render();
    }

    public function test_settings_shows_taxes_under_store_and_invoices_under_sales(): void
    {
        $this->actingAs($this->user(['view_settings/settings_hub', 'view_settings/tax', 'view_settings/invoice']));

        $this->get(route('settings.hub'))->assertOk()
            ->assertSee(route('taxes.index'))
            ->assertSee(route('settings.invoices'));
    }

    public function test_the_first_tax_rate_is_the_default_and_the_default_cannot_be_deleted(): void
    {
        $this->actingAs($this->user(['view_settings/tax', 'manage_settings/tax']));

        $this->post(route('taxes.store'), ['name' => 'VAT', 'rate' => '12'])->assertRedirect(route('taxes.index'));
        $vat = TaxRate::firstWhere('name', 'VAT');
        $this->assertTrue($vat->is_default);

        $this->delete(route('taxes.destroy', $vat->id))->assertSessionHas('error');
        $this->assertNotNull(TaxRate::find($vat->id));

        $this->post(route('taxes.store'), ['name' => 'Zero-rated', 'rate' => '0', 'is_default' => '1']);
        $this->assertFalse($vat->fresh()->is_default, 'making another rate the default takes it off the old one');
        $this->assertSame(1, TaxRate::where('is_default', true)->count());

        $this->delete(route('taxes.destroy', $vat->id))->assertSessionHas('success');
        $this->assertNull(TaxRate::find($vat->id));
    }

    public function test_the_registration_keeps_digits_only_and_checks_their_count(): void
    {
        $this->actingAs($this->user(['view_settings/tax', 'manage_settings/tax']));

        $this->put(route('taxes.registration'), ['tax_status' => 'vat', 'tin' => '123-456-789', 'branch_code' => '', 'business_style' => 'Example Audio Supplies'])
            ->assertSessionHasNoErrors();
        $settings = InvoiceSettings::current();
        $this->assertSame('123456789', $settings->tin);
        $this->assertSame('123-456-789-00000', $settings->fullTin());

        $this->put(route('taxes.registration'), ['tax_status' => 'vat', 'tin' => '1234', 'branch_code' => '00000'])
            ->assertSessionHasErrors('tin');
    }

    public function test_invoice_numbers_run_in_the_order_they_are_given_and_stay_on_their_order(): void
    {
        $this->actingAs($this->user(['view_sales/order', 'manage_sales/order']));
        $first = $this->order();
        $second = $this->order();

        $this->post(route('orders.invoice', $second->order_id))->assertRedirect(route('orders.show', $second->order_id));
        $this->post(route('orders.invoice', $first->order_id));
        $this->post(route('orders.invoice', $first->order_id));

        $this->assertSame('INV-000001', Invoice::firstWhere('order_id', $second->order_id)->label());
        $this->assertSame('INV-000002', Invoice::firstWhere('order_id', $first->order_id)->label());
        $this->assertSame(2, Invoice::count(), 'pressing it again gives no second number');

        InvoiceSettings::current()->update(['prefix' => 'INV-2027-']);
        $third = $this->order();
        $this->post(route('orders.invoice', $third->order_id));
        $this->assertSame('INV-2027-000001', Invoice::firstWhere('order_id', $third->order_id)->label());
    }

    public function test_generating_needs_edit_orders_and_the_pdf_needs_view_orders(): void
    {
        $order = $this->order();

        $this->actingAs($this->user(['view_sales/order']));
        $this->get(route('orders.pdf', $order->order_id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post(route('orders.invoice', $order->order_id))->assertStatus(302);
        $this->assertSame(0, Invoice::count(), 'view only cannot give a number');

        $this->actingAs($this->user(['view_sales/order', 'manage_sales/order']));
        $this->post(route('orders.invoice', $order->order_id));
        $this->assertSame(1, Invoice::count());

        $this->assertArrayNotHasKey('sales/order_invoice', app(PermissionCatalog::class)->all(), 'no separate tick for the order PDF and number');
    }

    public function test_the_pdf_is_an_order_until_a_number_is_given_then_an_invoice(): void
    {
        $order = $this->order();

        $before = $this->document($order);
        $this->assertStringContainsString('ORDER', $before);
        $this->assertStringNotContainsString('INVOICE', $before);
        $this->assertStringNotContainsString('INV-', $before);

        $this->actingAs($this->user(['view_sales/order', 'manage_sales/order']));
        $this->get(route('orders.pdf', $order->order_id))->assertHeader('content-disposition', 'inline; filename=order-' . $order->order_id . '.pdf');

        $this->post(route('orders.invoice', $order->order_id));
        $after = $this->document($order);
        $this->assertStringContainsString('INVOICE', $after);
        $this->assertStringContainsString('No. INV-000001', $after);
        $this->get(route('orders.pdf', $order->order_id))->assertHeader('content-disposition', 'inline; filename=INV-000001.pdf');
    }

    public function test_vat_is_worked_out_of_the_total_at_the_default_rate(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 12, 'is_default' => true]);
        $order = $this->order(1660);

        $tax = app(InvoiceDocument::class)->for($order)['tax'];
        $this->assertSame(1482.14, $tax['taxable']);
        $this->assertSame(177.86, $tax['tax']);
        $this->assertStringContainsString('VATable sales', $this->document($order));

        InvoiceSettings::current()->update(['tax_status' => InvoiceSettings::NON_VAT]);
        $html = $this->document($order);
        $this->assertStringNotContainsString('VATable sales', $html);
        $this->assertStringContainsString('NOT VALID FOR CLAIM OF INPUT TAX', $html);
    }

    public function test_no_logo_prints_when_the_switch_is_off_or_none_is_uploaded(): void
    {
        $order = $this->order();

        $this->assertNull(app(InvoiceDocument::class)->for($order)['seller']['logo'], 'nothing stands in for a missing logo');

        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('company/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        \App\Models\Setting::singleton()->update(['logo_path' => 'company/logo.png']);
        $this->assertStringStartsWith('data:image/png;base64,', app(InvoiceDocument::class)->for($order)['seller']['logo']);

        InvoiceSettings::current()->update(['print_logo' => false]);
        $this->assertNull(app(InvoiceDocument::class)->for($order)['seller']['logo']);
    }

    public function test_the_order_page_offers_the_pdf_and_the_number_then_shows_the_number(): void
    {
        $order = $this->order();
        $this->actingAs($this->user(['view_sales/order', 'manage_sales/order']));

        $this->get(route('orders.show', $order->order_id))->assertOk()
            ->assertSee(route('orders.pdf', $order->order_id))
            ->assertSee('View PDF')
            ->assertSee('target="_blank"', false)
            ->assertSee('Generate invoice number')
            ->assertSee('invoice number INV-000001?', false);

        $this->post(route('orders.invoice', $order->order_id));

        $this->get(route('orders.show', $order->order_id))->assertOk()
            ->assertDontSee('Generate invoice number')
            ->assertSee('View PDF')
            ->assertSee('Invoice INV-000001');
    }

    public function test_view_only_staff_get_the_pdf_but_not_the_button(): void
    {
        $order = $this->order();
        $this->actingAs($this->user(['view_sales/order']));

        $this->get(route('orders.show', $order->order_id))->assertOk()
            ->assertSee(route('orders.pdf', $order->order_id))
            ->assertDontSee('Generate invoice number');
    }
}
