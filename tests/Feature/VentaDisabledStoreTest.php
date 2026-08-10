<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A turned-off Venta store is not written to.
 *
 * Venta has no sandbox and no sandbox mode. base_url on these rows is the real
 * storefront - https://shop-a.example, https://shop-b.example - so "turned
 * off" is the only thing standing between a button and a live shop.
 *
 * The console commands were fine: all three filter on enabled, and even
 * --store=N is AND-ed with it, so a disabled store cannot be forced from the
 * CLI. VentaProductGroupController was not. A comment there claimed that
 * "sync/cron paths use their own enabled-filtered lookups so disabled stores
 * never reach the marketplace API", and the dangerous half of that was false:
 * all six of its API-calling actions resolved their store through store_(),
 * which is findOrFail with no enabled test at all. Pushing products, stock or
 * prices, deleting a listing, or matching ids would each have written to a
 * live storefront the operator had switched off precisely to prevent that.
 *
 * Reads are deliberately still allowed on a disabled store: testing the
 * connection and pulling categories, brands and statuses is how a store gets
 * configured BEFORE it is enabled. None of them modify anything at Venta.
 */
class VentaDisabledStoreTest extends TestCase
{
    private const CONTROLLER = 'extensions/venta/Controllers/VentaProductGroupController.php';

    /** Every action that talks to Venta, and none of them may skip the guard. */
    private const WRITE_ACTIONS = [
        'syncId', 'syncIds', 'pushProducts', 'pushStock', 'pushPrices', 'deleteFromVenta',
    ];

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    /** The text of one method, so an assertion cannot pass on a sibling's code. */
    private function methodBody(string $source, string $method): string
    {
        if (! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $next = preg_match('/\n    (?:public|private) function /', $source, $n, PREG_OFFSET_CAPTURE, $start + 10)
            ? $n[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    public function test_every_write_action_resolves_its_store_through_the_guard(): void
    {
        $source = $this->source(self::CONTROLLER);

        foreach (self::WRITE_ACTIONS as $action) {
            $body = $this->methodBody($source, $action);

            $this->assertNotSame('', $body, "{$action} was not found; the guard list is stale.");

            $this->assertStringContainsString('$this->storeForWrite_($store)', $body,
                "Venta::{$action} resolves its store without the enabled guard, so it can write to a "
                .'live storefront that the operator has switched off.');

            $this->assertStringNotContainsString('$setting = $this->store_($store);', $body,
                "Venta::{$action} still uses the unguarded lookup.");
        }
    }

    /** The guard has to actually test the flag, not merely exist. */
    public function test_the_guard_refuses_a_disabled_store(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString('return $setting->enabled ? $setting : null;', $source,
            'storeForWrite_ does not test the enabled flag.');
    }

    /** And the refusal has to be visible, not a silent no-op. */
    public function test_a_refusal_tells_the_operator_why(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertSame(
            count(self::WRITE_ACTIONS),
            substr_count($source, 'That Venta store is turned off'),
            'A write action refuses a disabled store without saying so, or one of them stopped refusing.'
        );
    }

    /**
     * The console side, which was already correct and must stay that way.
     * --store=N is AND-ed with enabled, so a disabled store cannot be forced.
     */
    public function test_no_console_command_can_reach_a_disabled_store(): void
    {
        foreach (glob(base_path('extensions/venta/Commands/*.php')) as $path) {
            $this->assertStringContainsString("where('enabled', true)", file_get_contents($path),
                basename($path).' can run against a disabled Venta store.');
        }
    }
}
