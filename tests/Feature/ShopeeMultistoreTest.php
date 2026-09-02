<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-store Shopee (#194, umbrella #180): the promotion of the shape
 * Venta, OpenCart and Shopify already live in. Grows a section per
 * stage; S1 pins the ground - settings are rows and every storeless
 * read goes through one named accessor.
 */
class ShopeeMultistoreTest extends TestCase
{
    use RefreshDatabase;

    // ── S1: the ground ───────────────────────────────────────────────

    public function test_default_store_is_the_first_enabled_row(): void
    {
        $disabled = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Closed shop', 'enabled' => false]);
        $first = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->assertSame($first->id, ShopeeSetting::defaultStore()?->id,
            'defaultStore must return the first ENABLED row - a disabled store must never answer for the channel.');
    }

    public function test_no_store_at_all_answers_null_not_an_error(): void
    {
        $this->assertNull(ShopeeSetting::defaultStore());
    }

    /**
     * The whole point of S1: one spelling to re-aim in S4. A raw
     * singleton read reappearing would silently pin its caller to
     * "whatever row is first" forever.
     */
    public function test_nothing_reads_the_settings_singleton_raw(): void
    {
        $offenders = [];
        $scan = array_merge(
            glob(base_path('extensions/*/Controllers/*.php')) ?: [],
            glob(base_path('extensions/*/Commands/*.php')) ?: [],
            glob(base_path('extensions/*/Services/**/*.php')) ?: [],
            glob(base_path('extensions/*/Services/*.php')) ?: [],
            glob(base_path('extensions/*/Models/*.php')) ?: [],
            glob(base_path('extensions/*/*Extension.php')) ?: [],
            glob(base_path('app/**/*.php')) ?: [],
        );
        $this->assertNotEmpty($scan, 'the scan found no files; the guard is scanning nothing');

        foreach ($scan as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "unreadable: {$path}");
            if (str_contains($source, 'ShopeeSetting::query()->first()') || str_contains($source, 'ShopeeSetting::first()')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'These files read the Shopee settings singleton raw instead of ShopeeSetting::defaultStore(): '
            . implode(', ', $offenders));
    }

    public function test_the_existing_row_became_store_one_with_a_name(): void
    {
        /* The migration names the first row so the store list never shows
         * an anonymous entry; a fresh install with no row stays empty. */
        $s = ShopeeSetting::create(['mode' => 'live']);
        $this->assertTrue((bool) $s->fresh()->enabled, 'a row created the old way is an enabled store by default');
    }
}
