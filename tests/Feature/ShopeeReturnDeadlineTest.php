<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeReturn;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The one clock on a return that belongs to the seller.
 *
 * Shopee sends four deadlines with a return and only one of them is an
 * obligation of yours:
 *
 *   return_seller_due_date      you must respond by this          <- this one
 *   return_ship_due_date        the BUYER must ship it back by this
 *   due_date                    the return's general clock
 *   negotiation.offer_due_date  the counter-offer window
 *
 * Picking the wrong field would put a countdown on someone else's obligation,
 * which reads as urgent and is not actionable. These fix the choice.
 */
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

    /**
     * The buyer's shipping deadline must never stand in for the seller's. A
     * return with only that field has nothing to show the seller.
     */
    public function test_the_buyers_shipping_deadline_is_not_borrowed(): void
    {
        $model = $this->returnWithRaw([
            'return_ship_due_date' => 1786000000,
            'due_date' => 1786000000,
        ]);

        $this->assertNull($model->sellerDueAt());
    }

    /**
     * Shopee zeroes this field once there is nothing left for you to answer,
     * which is why a cancelled return shows no countdown. Zero must read as
     * "nothing to show", never as 1 January 1970 - which would render as the
     * most overdue thing on the page.
     */
    public function test_zero_and_absent_both_mean_nothing_to_show(): void
    {
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => 0])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => '0'])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => null])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => ''])->sellerDueAt());
        $this->assertNull($this->returnWithRaw([])->sellerDueAt());
        $this->assertNull($this->returnWithRaw(['return_seller_due_date' => 'soon'])->sellerDueAt());
    }

    /**
     * Same class of bug the Lazada ship-by handling already carries a guard
     * for: a millisecond timestamp renders as a date tens of thousands of
     * years away rather than failing, so the countdown reads as fine forever.
     */
    public function test_a_millisecond_timestamp_is_converted_not_trusted(): void
    {
        $this->assertSame(1786000000, $this->returnWithRaw(['return_seller_due_date' => 1786000000000])->sellerDueAt());
    }

    /**
     * The chip serves two surfaces now - a shipping deadline on orders and a
     * respond-by deadline on Shopee returns - so its tooltip and its
     * screen-reader text have to name the deadline they were given. Both
     * hardcoded "ship by", which told a returns operator the wrong thing
     * about the right date.
     */
    public function test_the_chip_names_whichever_deadline_it_was_given(): void
    {
        $html = $this->blade(
            '<x-sla-chip :deadline="$d" label="Respond by" />',
            ['d' => time() - 90000]
        )->__toString();

        $this->assertStringContainsString('title="Respond by', $html);
        $this->assertStringContainsString('(respond by', $html);
        $this->assertStringNotContainsString('ship by', $html);
        $this->assertStringNotContainsString('Ship by', $html);

        // The visible label still flips to Overdue; only the date keeps the
        // caller's wording.
        $this->assertStringContainsString('Overdue', $html);
    }

    public function test_the_shopee_returns_row_shows_the_respond_by_clock(): void
    {
        $panel = File::get(base_path('extensions/shopee/views/orders/_returns_panel.blade.php'));

        $this->assertStringContainsString('sellerDueAt()', $panel);
        $this->assertStringContainsString('label="Respond by"', $panel);
    }

    /**
     * Lazada's returns abstain, and that is a decision rather than an
     * oversight.
     *
     * Its reverse-order payload carries return_order_line_gmt_create,
     * _gmt_modified and trade_order_gmt_create - when things happened - and no
     * deadline of any kind. Adding a countdown there would mean deriving one
     * from a creation date and a guessed window, which is inventing data to
     * make two pages match. If Lazada ever ships a real deadline field, delete
     * this test in the same change that reads it.
     */
    public function test_lazada_returns_do_not_invent_a_deadline(): void
    {
        $panel = File::get(base_path('extensions/lazada/views/orders/_returns_panel.blade.php'));

        $this->assertStringNotContainsString(
            'sla-chip',
            $panel,
            "Lazada's reverse-order payload has no deadline field. A countdown here would be derived, "
            ."not reported - see this test's docblock before adding one."
        );
    }
}
