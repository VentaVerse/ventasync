<?php

namespace Tests\Feature;

use App\Support\Fulfilment\OrderItemGrouper;
use App\Support\Fulfilment\PackedParcel;
use Tests\TestCase;

/**
 * The grouping that decides what a packer reads.
 *
 * This lived inline in the Lazada panel view and is still duplicated in the
 * Shopee and TikTok panels. It moved to a service when the packed-parcel
 * modal needed the same answer; these are the cases the inline version was
 * carrying and nobody could see, because a Blade block has no tests.
 */
class OrderItemGrouperTest extends TestCase
{
    /**
     * Lazada emits one row per UNIT on several payload shapes. Three of the
     * same cable must read "x3", not appear three times - a packer reading a
     * repeated line counts by hand, which is the error this prevents.
     */
    public function test_identical_products_become_one_line_with_a_summed_quantity(): void
    {
        $grouped = OrderItemGrouper::group([
            ['name' => 'Qable TS50', 'sku' => 'QB-TS50', 'variation' => '3 Meters', 'quantity' => 1],
            ['name' => 'Qable TS50', 'sku' => 'QB-TS50', 'variation' => '3 Meters', 'quantity' => 1],
            ['name' => 'Qable TS50', 'sku' => 'QB-TS50', 'variation' => '3 Meters', 'quantity' => 1],
        ]);

        $this->assertCount(1, $grouped);
        $this->assertSame(3, $grouped[0]['quantity']);
        $this->assertSame(3, OrderItemGrouper::unitCount($grouped));
    }

    /**
     * Same product, different variation, is a different thing to put in a box.
     * Grouping on name alone would merge a 3-metre cable with a 10-metre one.
     */
    public function test_the_same_product_in_two_variations_stays_two_lines(): void
    {
        $grouped = OrderItemGrouper::group([
            ['name' => 'Qable TS50', 'sku' => 'QB-TS50', 'variation' => '3 Meters', 'quantity' => 1],
            ['name' => 'Qable TS50', 'sku' => 'QB-TS50', 'variation' => '10 Feet', 'quantity' => 2],
        ]);

        $this->assertCount(2, $grouped);
        $this->assertSame(3, OrderItemGrouper::unitCount($grouped));
    }

    /**
     * The marketplaces disagree about field names for the same fact, and the
     * payload shape differs by which endpoint answered.
     */
    public function test_it_reads_every_field_alias_the_marketplaces_send(): void
    {
        $grouped = OrderItemGrouper::group([
            ['raw' => ['item_name' => 'Ballpen', 'SellerSKU' => 'BP-1', 'Variation' => 'Blue', 'qty' => 2]],
        ]);

        $this->assertSame('Ballpen', $grouped[0]['name']);
        $this->assertSame('BP-1', $grouped[0]['sku']);
        $this->assertSame('Blue', $grouped[0]['variation']);
        $this->assertSame(2, $grouped[0]['quantity']);
    }

    /**
     * "Blank" and "null" arrive as literal text, not as an absent field.
     * Printing "Variation: null" on a picking list is worse than nothing.
     */
    public function test_a_variation_that_means_nothing_is_dropped(): void
    {
        foreach (['', 'Blank', 'NULL', 'n/a', '-', '--', 'none'] as $noise) {
            $grouped = OrderItemGrouper::group([
                ['name' => 'Ballpen', 'sku' => 'BP-1', 'variation' => $noise, 'quantity' => 1],
            ]);

            $this->assertSame('', $grouped[0]['variation'], "\"{$noise}\" should not reach a packer as a variation");
        }
    }

    /**
     * An order line that exists describes something that goes in a box, so a
     * missing or zero quantity is one unit. A zero would drop it out of the
     * count entirely and the packer would ship short.
     */
    public function test_a_missing_or_zero_quantity_counts_as_one_unit(): void
    {
        $grouped = OrderItemGrouper::group([
            ['name' => 'Ballpen', 'sku' => 'BP-1'],
            ['name' => 'Cable', 'sku' => 'C-1', 'quantity' => 0],
            ['name' => 'Strap', 'sku' => 'S-1', 'quantity' => -4],
        ]);

        $this->assertSame([1, 1, 1], array_column($grouped, 'quantity'));
        $this->assertSame(3, OrderItemGrouper::unitCount($grouped));
    }

    /**
     * One unit of a pair can carry the photo and the other not. The line must
     * not end up imageless because the bare row happened to sort first.
     */
    public function test_the_first_photo_found_wins_for_a_grouped_line(): void
    {
        $grouped = OrderItemGrouper::group([
            ['name' => 'Ballpen', 'sku' => 'BP-1', 'quantity' => 1, 'image' => ''],
            ['name' => 'Ballpen', 'sku' => 'BP-1', 'quantity' => 1, 'image' => 'https://example.test/p.jpg'],
        ]);

        $this->assertCount(1, $grouped);
        $this->assertSame('https://example.test/p.jpg', $grouped[0]['image']);
    }

    /**
     * A malformed row must not take the page down. The panel renders whatever
     * the marketplace last wrote, and that is not always a list of arrays.
     */
    public function test_a_row_that_is_not_an_array_is_skipped_rather_than_fatal(): void
    {
        $grouped = OrderItemGrouper::group(['a string', null, 42, ['name' => 'Ballpen', 'sku' => 'BP-1']]);

        $this->assertCount(1, $grouped);
        $this->assertSame('Ballpen', $grouped[0]['name']);
    }

    /**
     * The modal falls back to the order number alone rather than rendering a
     * parcel it could not resolve. A confirmation showing a WRONG parcel is
     * worse than one showing none.
     */
    public function test_an_unresolvable_parcel_is_null_rather_than_empty(): void
    {
        $this->assertNull(PackedParcel::fromOrder(null, '1105065607396146'));
        $this->assertNull(PackedParcel::fromOrder((object) [], ''));
    }
}
