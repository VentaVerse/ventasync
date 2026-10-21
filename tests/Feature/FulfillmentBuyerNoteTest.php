<?php

namespace Tests\Feature;

use App\Support\Fulfillment\BuyerNotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FulfillmentBuyerNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BuyerNotes::forget();
    }

    private function order(string $note): int
    {
        $row = ['customer_note' => $note];
        foreach (DB::select("SELECT COLUMN_NAME c, DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'") as $col) {
            $row[$col->c] ??= in_array($col->t, ['int', 'bigint', 'tinyint', 'smallint', 'decimal', 'float', 'double'], true) ? 0
                : (in_array($col->t, ['datetime', 'timestamp', 'date'], true) ? now() : '');
        }

        return (int) DB::table('orders')->insertGetId($row);
    }

    public function test_the_note_row_shows_the_buyers_note_escaped(): void
    {
        $id = $this->order('Gift wrap <b>please</b>');

        $html = Blade::render('<table><x-fulfillment.buyer-note :order="$id" :colspan="7" /></table>', ['id' => $id]);

        $this->assertStringContainsString('Buyer note', $html);
        $this->assertStringContainsString('colspan="7"', $html);
        $this->assertStringContainsString('Gift wrap &lt;b&gt;please&lt;/b&gt;', $html);
    }

    public function test_no_note_draws_no_row(): void
    {
        $id = $this->order('   ');

        $this->assertStringNotContainsString('co-note', Blade::render('<x-fulfillment.buyer-note :order="$id" />', ['id' => $id]));
        $this->assertStringNotContainsString('co-note', Blade::render('<x-fulfillment.buyer-note :order="null" />'));
    }

    public function test_the_packing_dialog_repeats_the_note(): void
    {
        $id = $this->order('Leave it at the guard house.');
        $parcel = ['items' => [['name' => 'Cable', 'quantity' => 1]], 'unit_count' => 1, 'line_count' => 1, 'sales_order_id' => $id];

        $html = Blade::render('<x-fulfillment.parcel :parcel="$parcel" />', ['parcel' => $parcel]);

        $this->assertStringContainsString('Leave it at the guard house.', $html);
    }

    public function test_one_query_loads_a_page_of_notes(): void
    {
        $ids = [$this->order('One'), $this->order('Two'), $this->order('')];
        DB::enableQueryLog();
        BuyerNotes::prime($ids);
        foreach ($ids as $id) {
            BuyerNotes::of($id);
        }
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_every_order_panel_draws_the_note_row(): void
    {
        foreach (['shopee', 'lazada', 'tiktok', 'ventacart', 'woocommerce', 'opencart', 'shopify'] as $channel) {
            $file = base_path("extensions/{$channel}/views/orders/_panel.blade.php");
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('<x-fulfillment.buyer-note', $source, $channel);
            $this->assertStringContainsString('BuyerNotes::prime(', $source, $channel);
        }
    }
}
