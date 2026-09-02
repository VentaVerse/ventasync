<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What the push column is allowed to claim.
 *
 * A product whose push had just been refused by Shopee for an invalid category
 * displayed a green "Pushed" badge, a Shopee item id, and an empty reason
 * column. Three separate faults said the same untrue thing:
 *
 *  1. The badge tested "has an item id" BEFORE "failed", so any row carrying an
 *     id read as pushed regardless of status. The Failed badge was unreachable
 *     for every product that had ever been given an id.
 *  2. The failure path saved sync_status and discarded the message, so the
 *     reason column - which exists for exactly this - stayed blank.
 *  3. Unlinking left the pivot's item id behind, so a dead production id
 *     kept satisfying test 1 long after the link itself was deleted.
 *
 * These are source-level assertions rather than rendered-page ones because the
 * defect was an ordering mistake in a template, and ordering is what has to be
 * held still. A row states its most recent outcome, never the best outcome it
 * has ever had.
 */
class ShopeePushStatusTest extends TestCase
{
    private const VIEW = 'extensions/shopee/views/product-groups/products.blade.php';
    private const CONTROLLER = 'extensions/shopee/Controllers/ShopeeProductGroupController.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_the_columns_read_the_product_not_the_pivots_copy(): void
    {
        /* The original guard held a template ORDERING still: failure had to
         * be tested before the pivot's (possibly stale) item id, or a
         * failed push wearing an old id read as Pushed. Stage 1 of the
         * one-truth repointing (2026-08-24) removed the disease's organ:
         * the id and the Listed state come from shopee_product_links - the
         * record every page shares - and the pivot's failure renders as
         * this group's ATTEMPT, unconditionally, in its own words. What
         * must now hold still is that the display never reaches back into
         * the pivot's copies of product facts. */
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString('$rowLink->shopee_item_id', $view,
            'The Shopee ID column must read the product link, not the pivot copy.');
        $this->assertStringNotContainsString('$pivot->shopee_item_id', $view,
            'The pivot item id is a private copy that drifts; the page must not display it.');
        $this->assertStringContainsString('@if($attemptFailed)', $view,
            'The group\'s own failed attempt must still be told.');
        $this->assertStringContainsString("This preset's last push", $view,
            'The failure must name itself as this group\'s attempt, not as the product\'s status.');
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

    /**
     * A page load must not decide that a push succeeded.
     *
     * Found by rendering the page against a seeded failure, not by reading:
     * the row went in as error-with-no-item-id and came out as synced with an
     * item id, WITH push_error still set. It rendered as a green Pushed badge
     * sitting beside the words "invalid category". Every refresh laundered a
     * failure into a success, which is most of why a failed push kept looking
     * like it had worked.
     *
     * The back-fill exists to adopt links made elsewhere. That is fine. It
     * must never overwrite a recorded failure, and a GET has no business
     * concluding anything about a push.
     */
    public function test_loading_the_page_writes_nothing(): void
    {
        /* Was: the read-time back-fill must not overwrite an error row.
         * Stage 2 (2026-08-25) removed the back-fill outright: the display
         * reads shopee_product_links, so the page no longer needs a write
         * to be right - and a GET on a view-tier route once again mutates
         * nothing. This holds the removal still. */
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

    /**
     * The filter and the badge must answer the same question. 'pushed' was
     * "has an item id and is not unlinked", which included failures, so
     * filtering to Pushed returned rows the badge calls Failed, in one
     * screenful.
     */
    public function test_the_listed_filter_answers_the_links_table(): void
    {
        /* Was: the Pushed filter must exclude pivot failures. Under the
         * one-truth model the filter and the badge answer the same record
         * by construction: Listed = a row in shopee_product_links, on this
         * page and on the Listings page alike. */
        $controller = $this->source(self::CONTROLLER);

        $this->assertStringContainsString("whereIn('p.product_id', \$linkedProductIds)", $controller,
            'The Listed filter must answer the links table, the same record the badge reads.');
        $this->assertStringContainsString("whereNotIn('p.product_id', \$linkedProductIds)", $controller,
            'The Not listed filter must be the exact complement of Listed.');
    }

    /**
     * Four states on the badge, four ways to filter to them. Unlinked products
     * could previously only be found by eye.
     */
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

    /**
     * push_error is stored at 480 characters and the cell printed 120 with no
     * title and no expansion, so up to 360 characters of what the marketplace
     * said were unreachable. A tooltip would not have fixed it: it waits a
     * second, never appears on a phone, and no keyboard reaches it.
     */
    public function test_the_full_failure_reason_is_reachable(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString('<details class="cc-reason">', $view,
            'A truncated failure reason has no expansion, so the operator cannot read it.');
        $this->assertStringContainsString('cc-reason__full">{{ $reason }}', $view,
            'The expansion does not carry the untruncated reason.');
    }

    /**
     * The push summary named up to five failures and vanished on the next
     * click. The count is taken across the whole group so it cannot quietly
     * shrink as the list is narrowed.
     */
    public function test_failures_outlive_the_banner_that_announced_them(): void
    {
        $this->assertStringContainsString("'failedCount' => $failedCount", $this->source(self::CONTROLLER),
            'The page is not told how many products failed, so nothing can survive the flash message.');

        $this->assertStringContainsString("'sync_status' => 'error', 'page' => null", $this->source(self::VIEW),
            'The failure count does not link to the rows it counts.');
    }

    /**
     * The reason column must distinguish "failed, reason unknown" from success.
     * Blank in a column that only ever fills on failure reads as nothing wrong.
     */
    public function test_a_failure_without_a_saved_reason_still_says_it_failed(): void
    {
        $this->assertStringContainsString(
            "'No reason was recorded.'",
            $this->source(self::VIEW),
            'A failed push with no recorded message shows an empty reason column.'
        );
    }
}
