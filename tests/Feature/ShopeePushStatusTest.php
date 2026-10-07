<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShopeePushStatusTest extends TestCase
{
    private const VIEW = 'extensions/shopee/views/product-groups/products.blade.php';
    private const CELL = 'resources/views/partials/channel-error-row.blade.php';
    private const CONTROLLER = 'extensions/shopee/Controllers/ShopeeProductGroupController.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_the_columns_read_the_product_not_the_pivots_copy(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString('$rowLink->shopee_item_id', $view,
            'The Shopee ID column must read the product link, not the pivot copy.');
        $this->assertStringNotContainsString('$pivot->shopee_item_id', $view,
            'The pivot item id is a private copy that drifts; the page must not display it.');
        $this->assertStringContainsString("@include('partials.channel-error-row'", $view,
            'The group page must draw the shared error line.');
        $this->assertStringContainsString("@if(\$error !== '')", $this->source(self::CELL),
            'The error line must draw nothing when nothing is wrong.');
    }

    public function test_a_failed_push_records_why(): void
    {
        $controller = $this->source(self::CONTROLLER);

        $this->assertMatchesRegularExpression(
            "/'sync_status' => 'error',\s*\n\s*'push_error' =>/",
            $controller,
            'The push failure path saves the status without the reason, so the operator is told '
            .'that something failed and never what.'
        );
    }

    public function test_a_successful_push_clears_the_previous_failure(): void
    {
        $controller = $this->source(self::CONTROLLER);

        $this->assertMatchesRegularExpression(
            "/'sync_status' => 'pushed',(?:.*\n)*?\s*'push_error' => null,/",
            $controller,
            'A product that failed once and then pushed cleanly keeps displaying the old reason.'
        );
    }

    public function test_unlinking_clears_the_pivot_item_id(): void
    {
        $this->assertMatchesRegularExpression(
            "/'shopee_item_id' => null,(?:.*\n)*?\s*'sync_status' => 'unlinked',/",
            $this->source('extensions/shopee/Models/ShopeeProductLink.php'),
            'Unlinking leaves the item id on the pivot, so a dead id keeps being displayed '
            .'against a product that is no longer linked.'
        );
    }

    public function test_loading_the_page_writes_nothing(): void
    {
        $controller = $this->source(self::CONTROLLER);

        $this->assertStringNotContainsString('canWriteBackfill', $controller,
            'The GET-time back-fill has regrown; a page load must not write pivot rows.');
        $this->assertStringNotContainsString('on page load', $controller,
            'Something is again mutating rows during a page load.');

        $this->assertStringNotContainsString(
            "'sync_status' => 'synced',",
            $controller,
            "'synced' is a fifth status word for a fourth state, and the back-fill was its only writer."
        );
    }

    public function test_the_listed_filter_answers_the_links_table(): void
    {
        $controller = $this->source(self::CONTROLLER);

        $this->assertStringContainsString("whereIn('p.product_id', \$linkedProductIds)", $controller,
            'The Listed filter must answer the links table, the same record the badge reads.');
        $this->assertStringContainsString("whereNotIn('p.product_id', \$linkedProductIds)", $controller,
            'The Not listed filter must be the exact complement of Listed.');
    }

    public function test_the_filter_offers_every_state_the_badge_shows(): void
    {
        $view = $this->source(self::VIEW);

        foreach (['pushed', 'pending', 'error', 'unlinked'] as $state) {
            $this->assertStringContainsString(
                "value=\"{$state}\"",
                $view,
                "The push filter cannot select the '{$state}' state the badge displays."
            );
        }

        $this->assertStringContainsString(
            "elseif (\$syncStatus === 'unlinked')",
            $this->source(self::CONTROLLER),
            'The unlinked filter option is offered but the controller ignores it, so it silently returns everything.'
        );
    }

    public function test_the_full_failure_reason_is_reachable(): void
    {
        $view = $this->source(self::CELL);

        $this->assertStringContainsString('{{ $error }}</td>', $view,
            'The error line does not carry the reason.');
        $this->assertStringNotContainsString('Str::limit', $view,
            'The error line clips the reason, so the operator cannot read all of it.');
    }

    public function test_failures_outlive_the_banner_that_announced_them(): void
    {
        $this->assertStringContainsString("'failedCount' => $failedCount", $this->source(self::CONTROLLER),
            'The page is not told how many products failed, so nothing can survive the flash message.');

        $this->assertStringContainsString("'sync_status' => 'error', 'page' => null", $this->source(self::VIEW),
            'The failure count does not link to the rows it counts.');
    }

    public function test_a_failure_without_a_saved_reason_still_says_it_failed(): void
    {
        $this->assertStringContainsString(
            'PushLedger::NO_REASON',
            $this->source('extensions/shopee/Services/Shopee/ShopeeListingStates.php'),
            'A failed sync with no recorded message draws no error line.'
        );
    }
}
