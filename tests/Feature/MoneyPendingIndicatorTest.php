<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Minor fix (final whole-branch review): orders/index.blade.php and
 * dashboard/index.blade.php both render <x-money :foreign="$o->foreign_total"
 * :foreign-code="$o->currency_code" />. A foreign order whose foreign_total
 * is still NULL (not yet normalized, or synced in the gap before a rate was
 * recorded) used to render as a bare, unlabelled PHP figure - identical to a
 * genuine PHP order - unlike show.blade.php, which shows a pending notice
 * for the exact same state. The fix teaches the shared <x-money> component
 * itself to distinguish the two cases, so both list pages get the fix at
 * once.
 */
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
