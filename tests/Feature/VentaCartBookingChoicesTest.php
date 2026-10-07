<?php

namespace Tests\Feature;

use Tests\TestCase;

class VentaCartBookingChoicesTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/ventacart-orders.js'));
    }

    public function test_the_parcel_and_schedule_start_at_choose_one(): void
    {
        $js = $this->js();

        $this->assertStringContainsString("'Choose a parcel size…'", $js,
            'the parcel must start unchosen - Small-by-default already cost a real booking');
        $this->assertStringContainsString("'Choose a schedule…'", $js);
        $this->assertStringContainsString("'Choose a pickup…'", $js);
        $this->assertStringNotContainsString('fillSelect(parcelEl, info.parcel_options, d.parcel)', $js,
            "the courier's default parcel must never preselect");
    }

    public function test_confirm_refuses_an_unanswered_choice_before_anything_is_sent(): void
    {
        $js = $this->js();

        $this->assertStringContainsString("showError('Choose ' + missing.map", $js);
        $this->assertStringContainsString("first. Nothing was booked.", $js);

        $gateAt = strpos($js, 'var missing = unanswered();', strpos($js, "confirmBtn.addEventListener"));
        $bookAt = strpos($js, 'postJson(urls.book', strpos($js, "confirmBtn.addEventListener"));
        $this->assertNotFalse($gateAt);
        $this->assertNotFalse($bookAt);
        $this->assertLessThan($bookAt, $gateAt, 'the gate must run before the booking request');
    }

    public function test_the_unchosen_pickup_sentinel_never_reaches_the_wire(): void
    {
        $this->assertStringContainsString("slotEl.value !== UNCHOSEN_SLOT", $this->js(),
            'the placeholder value must never be sent as a pickup slot');
    }
}
