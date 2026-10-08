<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\OrderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderEditKeepsStockTrueTest extends TestCase
{
    use RefreshDatabase;

    private string $p;

    private int $processing;

    private int $pending;

    private int $cancelled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = (string) config('catalog.prefix');
        $this->processing = (int) OrderStatus::create(['language_id' => 1, 'name' => 'Processing', 'subtract_stock' => 1, 'add_revenue' => 1])->order_status_id;
        $this->pending = (int) OrderStatus::create(['language_id' => 1, 'name' => 'Pending', 'subtract_stock' => 0, 'add_revenue' => 0])->order_status_id;
        $this->cancelled = (int) OrderStatus::create(['language_id' => 1, 'name' => 'Cancelled', 'subtract_stock' => 0, 'add_revenue' => 0])->order_status_id;

        $group = UserGroup::create(['name' => 'Order Managers']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_sales/order', 'view_sales/order'])->pluck('id')->all());
        $this->actingAs(User::factory()->create(['user_group_id' => $group->id]));
    }

    private function product(string $sku, int $qty): int
    {
        $id = DB::table($this->p . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => $qty, 'subtract' => 1, 'price' => 100, 'status' => 1, 'image' => '',
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($this->p . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id', 1),
            'name' => $sku, 'description' => '', 'tag' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);

        return $id;
    }

    private function variation(int $productId, string $sku, int $qty): int
    {
        return (int) DB::table($this->p . 'product_option_value')->insertGetId([
            'product_option_id' => 1, 'product_id' => $productId, 'option_id' => 1, 'option_value_id' => 1,
            'sku' => $sku, 'quantity' => $qty, 'subtract' => 1,
        ]);
    }

    private function qty(int $productId): int
    {
        return (int) DB::table($this->p . 'product')->where('product_id', $productId)->value('quantity');
    }

    private function line(int $productId, int $qty, array $extra = []): array
    {
        return ['product_id' => $productId, 'name' => 'P' . $productId, 'model' => 'P' . $productId, 'quantity' => $qty, 'price' => 100, 'cost' => 40] + $extra;
    }

    private function create(array $lines, ?int $status = null): int
    {
        $this->post(route('orders.store'), ['firstname' => 'Juan', 'order_status_id' => $status ?? $this->processing, 'products' => $lines])->assertRedirect();

        return (int) DB::table($this->p . 'order')->max('order_id');
    }

    private function edit(int $orderId, array $lines, ?int $status = null): void
    {
        $this->put(route('orders.update', $orderId), ['firstname' => 'Juan', 'order_status_id' => $status ?? $this->processing, 'products' => $lines])->assertRedirect();
    }

    public function test_adding_a_product_to_an_order_deducts_it(): void
    {
        $a = $this->product('A', 10);
        $b = $this->product('B', 10);
        $order = $this->create([$this->line($a, 2)]);
        $this->assertSame(8, $this->qty($a));

        $this->edit($order, [$this->line($a, 2), $this->line($b, 3)]);

        $this->assertSame(8, $this->qty($a), 'the line that did not change is not touched');
        $this->assertSame(7, $this->qty($b), 'the new line is deducted');
    }

    public function test_raising_and_lowering_a_quantity_moves_only_the_difference(): void
    {
        $a = $this->product('A', 10);
        $order = $this->create([$this->line($a, 2)]);

        $this->edit($order, [$this->line($a, 5)]);
        $this->assertSame(5, $this->qty($a));

        $this->edit($order, [$this->line($a, 1)]);
        $this->assertSame(9, $this->qty($a));
    }

    public function test_removing_a_product_gives_its_stock_back(): void
    {
        $a = $this->product('A', 10);
        $b = $this->product('B', 10);
        $order = $this->create([$this->line($a, 2), $this->line($b, 4)]);

        $this->edit($order, [$this->line($a, 2)]);

        $this->assertSame(8, $this->qty($a));
        $this->assertSame(10, $this->qty($b));
    }

    public function test_cancelling_while_changing_lines_gives_back_what_was_actually_deducted(): void
    {
        $a = $this->product('A', 10);
        $order = $this->create([$this->line($a, 2)]);

        $this->edit($order, [$this->line($a, 5)], $this->cancelled);

        $this->assertSame(10, $this->qty($a));
    }

    public function test_confirming_a_pending_order_with_new_lines_deducts_the_new_lines(): void
    {
        $a = $this->product('A', 10);
        $order = $this->create([$this->line($a, 2)], $this->pending);
        $this->assertSame(10, $this->qty($a), 'pending does not deduct');

        $this->edit($order, [$this->line($a, 3)], $this->processing);

        $this->assertSame(7, $this->qty($a));
    }

    public function test_saving_without_changes_moves_nothing_and_logs_nothing(): void
    {
        $a = $this->product('A', 10);
        $order = $this->create([$this->line($a, 2)]);
        $history = DB::table('stock_history')->count();

        $this->edit($order, [$this->line($a, 2)]);

        $this->assertSame(8, $this->qty($a));
        $this->assertSame($history, DB::table('stock_history')->count());
    }

    public function test_a_variation_line_moves_the_variation_and_the_product(): void
    {
        $c = $this->product('C', 5);
        $pov = $this->variation($c, 'C-RED', 5);
        $order = $this->create([$this->line($c, 1, ['option_value_id' => $pov])]);
        $this->assertSame(4, (int) DB::table($this->p . 'product_option_value')->where('product_option_value_id', $pov)->value('quantity'));

        $this->edit($order, [$this->line($c, 3, ['option_value_id' => $pov])]);

        $this->assertSame(2, (int) DB::table($this->p . 'product_option_value')->where('product_option_value_id', $pov)->value('quantity'));
        $this->assertSame(2, $this->qty($c));
    }
}
