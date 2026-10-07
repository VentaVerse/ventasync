<?php

namespace Tests\Feature;

use Extensions\lazada\Models\LazadaReverseOrder;
use Extensions\shopee\Models\ShopeeReturn;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ShopeeReturnDeadlineTest extends TestCase
{
    private function returnWithRaw(array $raw): ShopeeReturn
    {
        $model = new ShopeeReturn;
        $model->raw = $raw;

        return $model;
    }

    public function test_it_reads_the_sellers_own_deadline(): void
    {
        $due = 1786000000;

        $model = $this->returnWithRaw([
            'return_seller_due_date' => $due,
            'return_ship_due_date' => 1700000000,
            'due_date' => 1600000000,
        ]);

        $this->assertSame($due, $model->sellerDueAt());
    }

    public function test_the_buyers_shipping_deadline_is_not_borrowed(): void
    {
        $model = $this->returnWithRaw([
            'return_ship_due_date' => 1786000000,
            'due_date' => 1786000000,
        ]);

        $this->assertNull($model->sellerDueAt());
    }

    public function test_zero_and_absent_both_mean_nothing_to_show(): void
    {
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => 0])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => '0'])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => null])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => ''])->sellerDueAt());
        $this->assertNull($this->returnWithRaw([])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => 'soon'])->sellerDueAt());
    }

    public function test_a_millisecond_timestamp_is_converted_not_trusted(): void
    {
        $this->assertSame(1786000000, $this->returnWithRaw(['return_seller_due_date' => 1786000000000])->sellerDueAt());
    }

    public function test_the_chip_names_whichever_deadline_it_was_given(): void
    {
        $html = $this->blade(
            '<x-sla-chip :deadline="$d" label="Respond by" />',
            ['d' => time() - 90000]
        )->__toString();

        $this->assertStringContainsString('title="Respond by', $html);
        $this->assertStringNotContainsString('ship by', $html);
        $this->assertStringNotContainsString('Ship by', $html);

        $this->assertStringContainsString('sla-chip__when', $html);
        $this->assertStringContainsString(date('Y-m-d', time() - 90000), $html);

        $this->assertStringNotContainsString('(respond by', $html);

        $this->assertStringContainsString('Overdue', $html);
    }

    public function test_the_shopee_returns_row_shows_the_respond_by_clock(): void
    {
        $panel = File::get(base_path('extensions/shopee/views/orders/_returns_panel.blade.php'));

        $this->assertStringContainsString('sellerDueAt()', $panel);
        $this->assertStringContainsString('label="Respond by"', $panel);
    }

    public function test_lazada_reads_its_own_seller_sla(): void
    {
        $model = new LazadaReverseOrder;
        $model->raw = ['reverse_order_lines' => [['sla' => 1786000000000]]];

        $this->assertSame(1786000000, $model->sellerDueAt(), 'the documented millisecond sla must be converted');

        $panel = File::get(base_path('extensions/lazada/views/orders/_returns_panel.blade.php'));
        $this->assertStringContainsString('sellerDueAt()', $panel);
    }

    public function test_the_earliest_line_sets_the_orders_deadline(): void
    {
        $model = new LazadaReverseOrder;
        $model->raw = ['reverse_order_lines' => [
            ['sla' => 1786000000000],
            ['sla' => 1785000000000],
            ['sla' => 0],
            ['sla' => 1787000000000],
        ]];

        $this->assertSame(1785000000, $model->sellerDueAt());
    }

    public function test_lazada_shows_nothing_when_there_is_no_sla(): void
    {
        foreach ([
            [],
            ['reverse_order_lines' => []],
            ['reverse_order_lines' => [['sla' => 0]]],
            ['reverse_order_lines' => [['sla' => null]]],
            ['reverse_order_lines' => [['sla' => 'soon']]],
            ['reverse_order_lines' => [[]]],
            ['reverse_order_lines' => 'not an array'],
        ] as $raw) {
            $model = new LazadaReverseOrder;
            $model->raw = $raw;
            $this->assertNull($model->sellerDueAt(), 'no clock must stay no clock: '.json_encode($raw));
        }
    }
}
