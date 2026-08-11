<?php

namespace Tests\Feature;

use Extensions\venta\Controllers\VentaProductGroupController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The push asks Venta what is true before it decides what to do.
 *
 * Every action on a product row used to decide from the local link row - the
 * ERP's own memory - which is wrong in both directions often enough that it
 * produced a closed trap. Deleting a product in Venta's admin left the ERP
 * still believing it was there, so the row offered Update (which can only
 * answer 404) and hid Push. Unlink then set a status flag without removing the
 * link, so the row offered only "Link to Venta again", which read that
 * surviving row, found it, and reported success without contacting Venta at
 * all. Unlink to Link again to Unlink, forever, with no marketplace in the
 * circuit.
 *
 * reconcileLink runs first now, so the link agrees with Venta before anything
 * is sent. Its six verdicts are the whole state space of ERP-versus-Venta
 * divergence, and each one has exactly one correct next move.
 */
class VentaReconcileTest extends TestCase
{
    private const CONTROLLER = 'extensions/venta/Controllers/VentaProductGroupController.php';
    private const VIEW = 'extensions/venta/views/product-groups/products.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    /** Reconciling must happen before the create-or-update decision, or it is decoration. */
    public function test_the_push_reconciles_before_it_decides(): void
    {
        $source = $this->source(self::CONTROLLER);

        $reconcile = strpos($source, '$verdict = $this->reconcileLink(');
        $decide = strpos($source, 'if ($existingLink && $existingLink->venta_product_id) {');

        $this->assertNotFalse($reconcile, 'The push no longer reconciles at all.');
        $this->assertNotFalse($decide);
        $this->assertLessThan($decide, $reconcile,
            'The push decides create-versus-update before asking Venta, so it is guessing again.');
    }

    /** A link Venta cannot confirm is dropped, which is what turns the retry into a create. */
    public function test_a_link_venta_cannot_confirm_is_dropped(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString("'state' => 'lost'", $body,
            'Nothing recognises a product that has been removed on Venta.');
        $this->assertStringContainsString('$link->delete();', $body,
            'A stale link survives reconciliation, so the next push sends an update Venta can only 404.');
    }

    /** The six verdicts are the full state space; losing one loses a case. */
    public function test_every_divergence_has_a_verdict(): void
    {
        $body = $this->methodBody('reconcileLink');

        // Matched as bare quoted names, not as "'state' => 'x'": two of the
        // six are returned through a ternary, and pinning the syntax rather
        // than the meaning made this fail on correct code.
        foreach (['confirmed', 'adopted', 'repointed', 'lost', 'new', 'blocked'] as $state) {
            $this->assertStringContainsString("'{$state}'", $body,
                "reconcileLink no longer reports '{$state}', so that divergence is handled silently or not at all.");
        }
    }

    /** A collision is refused rather than resolved by stealing another product's link. */
    public function test_a_claimed_id_is_refused_not_taken(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString('$this->linkProductTo(', $body,
            'Reconciling writes the link directly instead of through the guard that checks who holds it.');
        $this->assertStringContainsString("'state' => 'blocked'", $body);
    }

    /**
     * The trap itself. One action, never gated, so no state of the page can
     * hide the thing that works.
     */
    public function test_the_row_always_offers_one_action_that_works(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString('Send to {{ $storeName }}', $view,
            'The row lost its single always-available action.');

        // Matched as rendered BUTTON labels. A bare string search also hit
        // the comment that explains which actions were replaced, which is
        // documentation rather than a control.
        foreach (['Push as new', 'Match Venta ID', 'Link to Venta again', 'Update on Venta'] as $gone) {
            $this->assertDoesNotMatchRegularExpression(
                '/>\s*'.preg_quote($gone, '/').'\s*<\/button>/',
                $view,
                "The row menu offers '{$gone}' again, which is one of the mutually exclusive states that made this a trap."
            );
        }
    }

    /** Unlink means forget. Leaving the link is what closed the loop. */
    public function test_unlink_removes_the_link(): void
    {
        $body = $this->methodBody('unlinkProduct');

        $this->assertStringContainsString('VentaProductLink::where', $body,
            'Unlink still only flips a status flag, so the ERP goes on believing the product is on Venta.');
        $this->assertStringContainsString('->delete();', $body);
    }

    /** The verdict reports what was found, never what followed from it. */
    public function test_the_summary_states_the_finding_not_the_outcome(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString("' had been removed on Venta'", $source);
        $this->assertStringNotContainsString("'was recreated'", $source,
            'The summary claims an outcome the push may not have reached, so a failed recreate still reads as one.');
    }

    /**
     * Venta's refusal to create without images is explained, not dumped as JSON.
     *
     * And explained as a RULE rather than a fault. Venta declines to create a
     * product whose images it cannot fetch on purpose, so that nothing reaches
     * the storefront without pictures - a half-listed product is live, wrong,
     * and silent, which is worse than an unlisted one. Wording that framed it
     * as a breakage invited someone to route around it.
     *
     * The operator also has to be told not to go looking in the catalogue: the
     * images are fine, the address they are served from is the problem.
     */
    public function test_the_image_wall_is_explained(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString('images_unavailable', $source);
        $this->assertStringContainsString('will not create a product it cannot fetch images for', $source,
            'Venta refusing to create a product without fetchable images is shown as a raw envelope.');
        $this->assertStringContainsString('Nothing in the catalogue needs fixing', $source,
            'The refusal does not tell the operator where the problem is not, so they will go hunting in the product.');
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionMethod(VentaProductGroupController::class, $method);
        $lines = file(base_path(self::CONTROLLER));

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
