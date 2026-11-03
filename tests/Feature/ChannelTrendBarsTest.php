<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelTrendBarsTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_and_orders_each_get_their_own_row_of_bars(): void
    {
        $html = view('partials._trend-bars', [
            'labels' => ['Nov 1', 'Nov 2', 'Nov 3'],
            'revenue' => [1200, 0, 600],
            'orders' => [2, 0, 1],
        ])->render();

        $this->assertSame(2, substr_count($html, '<svg class="cw-bars__svg'));
        $this->assertSame(4, substr_count($html, 'class="cw-bars__bar"'), 'a day with nothing draws no bar');
        $this->assertStringContainsString('peak ' . \App\Support\Money::base(1200), $html);
        $this->assertStringContainsString('peak 2', $html);
        $this->assertStringContainsString('data-bars-readout', $html);
        $this->assertStringNotContainsString('bl-trace', $html);
    }

    public function test_an_empty_period_draws_no_chart(): void
    {
        $html = view('partials._trend-bars', ['labels' => ['Nov 1', 'Nov 2'], 'revenue' => [0, 0], 'orders' => [0, 0]])->render();

        $this->assertStringNotContainsString('cw-bars', $html);
    }
}
