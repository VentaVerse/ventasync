<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A figure the settings page prints must be written by something.
 *
 * Venta's Connection tab used to show three "Last run" figures. Two of them
 * could never say anything but "Never":
 *
 *   Orders pulled    last_order_sync_at    written by the sync command  OK
 *   Products pushed  last_product_sync_at  written by NOTHING
 *   Stock pushed     last_stock_push_at    written by NOTHING
 *
 * The columns existed, the model cast them, the view printed them, and no code
 * path anywhere set them. So a stock push that updated 254 products
 * successfully still left the page reading "Stock pushed: Never", and the
 * operator's only reasonable conclusion was that the job had never run.
 *
 * This is the same defect this codebase has been carrying in several places:
 * a display reading a field nothing maintains. The card itself is gone now,
 * since each automation's own last run belongs beside the switch that
 * schedules it. The ratchet stays, written against the view rather than
 * against a list of columns, so the day one of these figures is printed there
 * again it is held to the same rule.
 */
class VentaLastRunFiguresTest extends TestCase
{
    private const VIEW = 'extensions/venta/views/settings/index.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    /** Everything under extensions/venta that is not the model or a view. */
    private function writerSources(): string
    {
        $out = '';

        foreach ([
            'extensions/venta/Commands',
            'extensions/venta/Controllers',
            'extensions/venta/Services',
        ] as $dir) {
            foreach (glob(base_path($dir.'/**/*.php')) + glob(base_path($dir.'/*.php')) as $path) {
                $out .= file_get_contents($path);
            }
        }

        return $out;
    }

    /**
     * The ratchet. Every last_*_at the settings page displays has to be set
     * somewhere that actually does the work.
     */
    public function test_every_timestamp_the_page_shows_is_written_by_something(): void
    {
        preg_match_all('/\$store->(last_\w+_at)/', $this->source(self::VIEW), $m);

        $displayed = array_unique($m[1]);

        // The page prints none today. The rule waits for the next one rather
        // than failing; a moved view would show up as the missing file.
        $this->assertFileExists(base_path(self::VIEW), 'the settings view moved; point this rule at its new path');
        if ($displayed === []) {
            return;
        }

        $writers = $this->writerSources();

        foreach ($displayed as $column) {
            $this->assertMatchesRegularExpression(
                "/'{$column}'\s*=>/",
                $writers,
                "The Venta settings page prints {$column}, but no command, controller or service ever "
                .'sets it, so that figure can only ever read "Never" however often the work succeeds.'
            );
        }
    }

    /** Named explicitly, because these two were the ones that were dead. */
    public function test_the_stock_push_records_that_it_ran(): void
    {
        $this->assertStringContainsString(
            "\$setting->update(['last_stock_push_at' => now()]);",
            $this->source('extensions/venta/Commands/VentaPushStock.php'),
            'Pushing stock leaves no trace, so the settings page says Never after a successful push.'
        );
    }

    public function test_the_product_push_records_that_it_ran(): void
    {
        $this->assertStringContainsString(
            "'last_product_sync_at' => now()",
            $this->source('extensions/venta/Controllers/VentaProductGroupController.php'),
            'Pushing products leaves no trace. This is the only path that pushes them - venta:sync '
            .'accepts only `orders` - so nothing else can stamp it.'
        );
    }

    /**
     * The stock stamp must not be conditional on a flawless run. A push where
     * 254 products succeeded and 5 failed did happen, and reporting "Never"
     * about it is the worse of the two inaccuracies.
     */
    public function test_a_partly_failed_stock_push_still_counts_as_having_run(): void
    {
        $source = $this->source('extensions/venta/Commands/VentaPushStock.php');

        $stampAt = strpos($source, "last_stock_push_at");
        $summaryAt = strpos($source, 'Done. OK:');

        $this->assertNotFalse($stampAt);
        $this->assertNotFalse($summaryAt);
        $this->assertLessThan($summaryAt, $stampAt,
            'The stamp moved after the summary line; it should run for every store the loop finished.');
    }
}
