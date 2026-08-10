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

    public function test_a_failed_push_is_reported_before_a_stale_item_id(): void
    {
        $view = $this->source(self::VIEW);

        $failed = strpos($view, '@elseif($failed)');
        $hasId = strpos($view, '@elseif($hasShopeeId)');

        $this->assertNotFalse($failed, 'The push column no longer reports failure at all.');
        $this->assertNotFalse($hasId, 'The push column no longer reports a pushed product.');

        $this->assertLessThan(
            $hasId,
            $failed,
            'The push column tests for an item id before it tests for failure, so a failed push '
            .'that happens to hold an id will be badged as Pushed.'
        );
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
     * The reason column must distinguish "failed, reason unknown" from success.
     * Blank in a column that only ever fills on failure reads as nothing wrong.
     */
    public function test_a_failure_without_a_saved_reason_still_says_it_failed(): void
    {
        $this->assertStringContainsString(
            '@if($failed && ! $hasError)',
            $this->source(self::VIEW),
            'A failed push with no recorded message shows an empty reason column.'
        );
    }
}
