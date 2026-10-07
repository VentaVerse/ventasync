<?php

namespace Tests\Unit;

use App\Support\Fulfilment\CourierTally;
use PHPUnit\Framework\TestCase;

class CourierTallyTest extends TestCase
{
    public function test_lazadas_two_leg_string_becomes_the_delivery_carrier(): void
    {
        $this->assertSame('J&T Express', CourierTally::displayName('Pickup: J&T Express PH, Delivery: J&T Express PH'));
        $this->assertSame('Flash Express', CourierTally::displayName('Pickup: Flash Express PH, Delivery: Flash Express PH'));
    }

    public function test_a_plain_carrier_name_passes_through_without_its_country_suffix(): void
    {
        $this->assertSame('SPX Express', CourierTally::displayName('SPX Express'));
        $this->assertSame('J&T Express', CourierTally::displayName('J&T Express PH'));
        $this->assertSame('Delivered by Seller', CourierTally::displayName('Delivered by Seller'));
    }

    public function test_no_courier_on_record_is_unknown_not_dropped(): void
    {
        $this->assertSame('Unknown', CourierTally::displayName(''));
        $this->assertSame('Unknown', CourierTally::displayName(null));
        $this->assertSame('Unknown', CourierTally::displayName('   '));
    }

    public function test_the_key_is_the_lowercased_name_so_a_url_can_carry_it(): void
    {
        $this->assertSame('j&t express', CourierTally::key('J&T Express'));
    }

    public function test_the_count_and_the_ids_come_from_the_same_walk(): void
    {
        $tally = new CourierTally();
        $tally->add(1, 'J&T Express PH');
        $tally->add(2, 'Pickup: J&T Express PH, Delivery: J&T Express PH');
        $tally->add(3, 'Flash Express');
        $tally->add(4, '');

        $this->assertSame([1, 2], $tally->idsFor('j&t express'));
        $this->assertSame([3], $tally->idsFor('flash express'));
        $this->assertSame([4], $tally->idsFor('unknown'));
        $this->assertNull($tally->idsFor('spx express'), 'A courier with no parcels is absent, not empty.');
    }

    public function test_the_strip_lists_the_busiest_courier_first_and_names_the_active_one(): void
    {
        $tally = new CourierTally();
        $tally->add(1, 'Flash Express');
        $tally->add(2, 'J&T Express');
        $tally->add(3, 'J&T Express');

        $strip = $tally->forStrip('flash express');

        $this->assertSame(3, $strip['total']);
        $this->assertSame('flash express', $strip['active']);
        $this->assertSame(['j&t express', 'flash express'], array_keys($strip['items']));
        $this->assertSame(['label' => 'J&T Express', 'count' => 2], $strip['items']['j&t express']);
    }

    public function test_a_stale_key_is_not_reported_as_active(): void
    {
        $tally = new CourierTally();
        $tally->add(1, 'Flash Express');

        $this->assertSame('', $tally->forStrip('j&t express')['active']);
    }

    public function test_an_empty_tally_renders_nothing(): void
    {
        $tally = new CourierTally();

        $this->assertTrue($tally->isEmpty());
        $this->assertSame([], $tally->forStrip()['items']);
    }
}
