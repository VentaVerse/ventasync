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

    public function test_unlinking_clears_the_pivot_item_id(): void
    {
        $this->assertMatchesRegularExpression(
            "/'shopee_item_id' => null,(?:.*\n)*?\s*'sync_status' => 'unlinked',/",
            $this->source('extensions/shopee/Models/ShopeeListingLink.php'),
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

    public function test_the_full_failure_reason_is_reachable(): void
    {
        $view = $this->source(self::CELL);

        $this->assertStringContainsString('{{ $error }}</td>', $view,
            'The error line does not carry the reason.');
        $this->assertStringNotContainsString('Str::limit', $view,
            'The error line clips the reason, so the operator cannot read all of it.');
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
