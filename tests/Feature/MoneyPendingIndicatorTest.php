<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyPendingIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_order_with_null_foreign_total_shows_a_pending_indicator(): void
    {
        $html = view('components.money', [
            'php' => 100.0,
            'foreign' => null,
            'foreignCode' => 'USD',
        ])->render();

        $this->assertStringContainsString('money-pending', $html);
        $this->assertStringContainsString('pending rate', $html);
    }

    public function test_php_order_never_shows_the_pending_indicator(): void
    {
        $html = view('components.money', [
            'php' => 100.0,
            'foreign' => null,
            'foreignCode' => 'PHP',
        ])->render();

        $this->assertStringNotContainsString('money-pending', $html);
    }

    public function test_normalized_foreign_order_shows_the_foreign_line_not_the_pending_indicator(): void
    {
        $html = view('components.money', [
            'php' => 6161.4295,
            'foreign' => 100.0,
            'foreignCode' => 'USD',
        ])->render();

        $this->assertStringContainsString('money-secondary', $html);
        $this->assertStringNotContainsString('money-pending', $html);
    }

    public function test_order_with_no_currency_code_never_shows_the_pending_indicator(): void
    {
        $html = view('components.money', [
            'php' => 100.0,
            'foreign' => null,
            'foreignCode' => null,
        ])->render();

        $this->assertStringNotContainsString('money-pending', $html);
    }
}
