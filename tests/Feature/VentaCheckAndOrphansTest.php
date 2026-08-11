<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Checking is a question, not a push, and an unanswered question is not a no.
 *
 * Three things are pinned here.
 *
 * FIRST, and most important: reconcileLink may only conclude that a product is
 * gone when Venta SAYS it is gone. The first draft tested nothing but
 * $result['ok'], which is false for a 404 and equally false for a dead socket,
 * a 500, or an expired token. A store that was merely unreachable therefore
 * read as a store that had deleted its entire catalogue, and the 'lost' branch
 * deletes links. One outage would have wiped every link in the group and
 * reported it as tidy housekeeping. Only an explicit 404 counts.
 *
 * SECOND: the page distinguishes what it has checked from what it believes.
 * Every status on this page was written at push time and never revisited, so
 * "Pushed" meant "a push once succeeded", which is a claim about the past
 * wearing the clothes of a claim about the present. last_confirmed_at is the
 * timestamp of the last time anybody actually asked.
 *
 * THIRD: the check reports findings and never pushes. It is the read half of
 * the pair; Send is the write half. If checking ever starts writing to Venta,
 * the confirm dialog that promises it will not becomes a lie.
 */
class VentaCheckAndOrphansTest extends TestCase
{
    private const CONTROLLER = 'extensions/venta/Controllers/VentaProductGroupController.php';
    private const PRODUCTS_VIEW = 'extensions/venta/views/product-groups/products.blade.php';
    private const ORPHANS_VIEW = 'extensions/venta/views/product-groups/orphans.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    private function methodBody(string $method): string
    {
        $source = $this->source(self::CONTROLLER);

        if (! preg_match('/\n    (?:public|private) function '.$method.'\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $m[0][1];
        $next = preg_match('/\n    (?:public|private) function /', $source, $n, PREG_OFFSET_CAPTURE, $start + 10)
            ? $n[0][1]
            : strlen($source);

        return substr($source, $start, $next - $start);
    }

    // ── Silence is not an answer ─────────────────────────────────────

    public function test_only_a_404_may_mean_the_product_is_gone(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString('$status !== 404', $body,
            'reconcileLink no longer separates "Venta said no" from "Venta said nothing", '
            .'so an unreachable store reads as an empty one and its links get deleted.');
    }

    public function test_an_unanswered_check_is_its_own_verdict(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString("'state' => 'unreachable'", $body,
            'The unreachable verdict is gone, so a failed lookup falls through to lost or new '
            .'and something gets concluded from no evidence.');

        // The guard has to come BEFORE the branch that deletes.
        $unreachableAt = strpos($body, "'state' => 'unreachable'");
        $lostAt = strpos($body, "'state' => 'lost'");

        $this->assertNotFalse($unreachableAt);
        $this->assertNotFalse($lostAt);
        $this->assertLessThan($lostAt, $unreachableAt,
            'The lost branch is reachable before the unreachable guard, so a store that never '
            .'answered can still have its links deleted.');
    }

    public function test_an_unreachable_product_is_never_pushed(): void
    {
        $body = $this->methodBody('pushProducts');

        $this->assertStringContainsString("\$verdict['state'] === 'unreachable'", $body,
            'pushProducts pushes after a check that failed, so a create can land on top of a '
            .'product that already exists.');
    }

    /**
     * And nothing is written about a product Venta did not answer for. The
     * previous row text stays, which is honest: it was a belief before the
     * check and it is still a belief after it.
     */
    public function test_an_unreachable_product_keeps_its_previous_row(): void
    {
        $body = $this->methodBody('checkAgainstVenta');

        $this->assertMatchesRegularExpression(
            "/'unreachable'\s*\)\s*\{(?:(?!writeProductStatus).)*?continue;/s",
            $body,
            'The check writes a status for a product Venta never answered about, so a guess is '
            .'being recorded as a finding.'
        );
    }

    // ── Checked versus believed ──────────────────────────────────────

    public function test_the_check_records_when_it_asked(): void
    {
        $this->assertStringContainsString("'last_confirmed_at' => now()", $this->methodBody('checkAgainstVenta'),
            'Checking leaves no trace, so the operator cannot tell a fresh answer from an old belief.');
    }

    public function test_a_push_also_counts_as_having_asked(): void
    {
        $this->assertStringContainsString("'last_confirmed_at' => now()", $this->methodBody('pushProducts'),
            'A push reconciles first but does not record that it did, so a row checked seconds ago '
            .'still reads as never checked.');
    }

    public function test_the_row_says_which_of_the_two_it_is(): void
    {
        $view = $this->source(self::PRODUCTS_VIEW);

        $this->assertStringContainsString('$confirmedAt', $view);
        $this->assertStringContainsString('Not checked since the push', $view,
            'A row with no confirmation says nothing, so a badge written months ago looks identical '
            .'to one confirmed a minute ago.');
    }

    public function test_the_column_is_actually_selected(): void
    {
        $this->assertStringContainsString("'vpgp.last_confirmed_at'", $this->source(self::CONTROLLER),
            'The view reads last_confirmed_at but the query does not select it, so every row '
            .'renders as never checked.');
    }

    // ── The check does not push ──────────────────────────────────────

    public function test_checking_never_writes_to_venta(): void
    {
        $body = $this->methodBody('checkAgainstVenta');

        foreach (['createProduct(', 'updateProduct(', 'updateVariant(', '$client->post(', '$client->put(', '$client->delete('] as $write) {
            $this->assertStringNotContainsString($write, $body,
                "The check calls {$write} - it is supposed to only ask questions, and the "
                .'confirm dialog promises it changes nothing on the store.');
        }
    }

    public function test_the_check_does_not_silently_cap_its_own_work(): void
    {
        $body = $this->methodBody('checkAgainstVenta');

        $this->assertStringContainsString('$skippedForSize', $body,
            'The check has no ceiling, so a large group runs until the request times out.');

        $this->assertStringContainsString('not checked this time', $body,
            'The check stops early without saying so, so unchecked products look confirmed.');
    }

    // ── The orphan report ────────────────────────────────────────────

    public function test_the_orphan_report_only_reads(): void
    {
        $body = $this->methodBody('orphans');

        foreach (['updateOrCreate(', '->delete()', 'linkProductTo(', 'writeProductStatus('] as $write) {
            $this->assertStringNotContainsString($write, $body,
                "The orphan report calls {$write}. Adopting a Venta product by SKU without asking "
                .'is how a catalogue product ends up pointed at a stranger.');
        }
    }

    public function test_the_orphan_report_admits_an_incomplete_read(): void
    {
        $body = $this->methodBody('orphans');

        $this->assertStringContainsString('may be incomplete', $body,
            'A failed page of results is dropped silently, so a partial list reads as the whole truth.');

        $this->assertStringContainsString('$truncated', $body,
            'The page ceiling is applied without being reported.');

        $this->assertStringContainsString('may hold more than is listed here', $this->source(self::ORPHANS_VIEW),
            'The view never surfaces truncation, so a cut-off list looks complete.');
    }

    /**
     * An orphan is decided by id, not by name similarity. Matching on a name
     * that merely looks alike is a guess, and this page exists to remove
     * guessing rather than to add a friendlier kind of it.
     */
    public function test_an_orphan_is_decided_by_the_link_table(): void
    {
        $body = $this->methodBody('orphans');

        $this->assertStringContainsString('$known->has($id)', $body,
            'Orphan detection no longer compares against the recorded links.');
    }
}
