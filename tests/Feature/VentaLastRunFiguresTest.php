<?php

namespace Tests\Feature;

use Tests\TestCase;

class VentaLastRunFiguresTest extends TestCase
{
    private const VIEW = 'extensions/venta/views/settings/index.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

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

    public function test_every_timestamp_the_page_shows_is_written_by_something(): void
    {
        preg_match_all('/\$store->(last_\w+_at)/', $this->source(self::VIEW), $m);

        $displayed = array_unique($m[1]);

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
