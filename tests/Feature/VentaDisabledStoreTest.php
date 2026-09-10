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
        'syncId', 'checkAgainstVenta', 'pushProducts', 'pushStock', 'pushPrices', 'deleteFromVenta',
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

        // Checked per method rather than by counting the phrase across the
        // file. The count was a proxy for "each write action says why", and it
        // broke the moment a READ action legitimately refused a disabled store
        // in the same words - a correct refusal failing a test about a
        // different set of methods. Asking each write action directly says what
        // was meant, and no longer cares what the rest of the file does.
        foreach (self::WRITE_ACTIONS as $action) {
            $body = $this->methodBody($source, $action);

            $this->assertNotSame('', $body, "{$action} was not found; the guard list is stale.");

            $this->assertStringContainsString('Venta store is turned off', $body,
                "Venta::{$action} refuses a disabled store without telling the operator why.");
        }
    }

    /**
     * Commands that READ ONLY, and why each is allowed to. The rule below
     * is about a command ACTING on a store that has been switched off -
     * syncing it, pushing to it, calling it. A command that only reads
     * what is already in this database is not that, and scoping one to
     * enabled stores would defeat it: a freshly connected store is
     * DISABLED until somebody turns syncing on, which is exactly the store
     * whose stored orders you need to look at.
     *
     * Anything added here must call no marketplace and write nothing.
     */
    private const READ_ONLY = [
        // Prints the fee-shaped fields the storefront sends, from payloads
        // already stored. Temporary; delete with the command.
        'VentaFeeKeys.php',
    ];

    /**
     * The console side, which was already correct and must stay that way.
     * --store=N is AND-ed with enabled, so a disabled store cannot be forced.
     */
    public function test_no_console_command_can_reach_a_disabled_store(): void
    {
        foreach (glob(base_path('extensions/venta/Commands/*.php')) as $path) {
            $source = (string) file_get_contents($path);

            if (in_array(basename($path), self::READ_ONLY, true)) {
                // The exemption is only worth having if it is checked: a
                // read-only command that grows a write is the thing this
                // rule exists to stop.
                foreach (['->update(', '->delete(', '->insert(', '->save()', 'client->', 'Client('] as $write) {
                    $this->assertStringNotContainsString($write, $source,
                        basename($path) . ' is listed as read-only and writes or calls out.');
                }
                continue;
            }

            $this->assertStringContainsString("where('enabled', true)", $source,
                basename($path).' can run against a disabled Venta store.');
        }
    }
}
