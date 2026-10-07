<?php

namespace Tests\Feature;

use Extensions\ventacart\Controllers\VentaCartProductGroupController;
use ReflectionMethod;
use Tests\TestCase;

class VentaCartReconcileTest extends TestCase
{
    private const CONTROLLER = 'extensions/ventacart/Controllers/VentaCartProductGroupController.php';
    private const VIEW = 'extensions/ventacart/views/product-groups/products.blade.php';

    private const SERVICE = 'extensions/ventacart/Services/VentaCart/VentaCartProductPush.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_the_push_reconciles_before_it_decides(): void
    {
        $source = $this->source(self::CONTROLLER);

        $reconcile = strpos($source, '$verdict = $this->reconcileLink(');
        $decide = strpos($source, 'if ($existingLink && $existingLink->ventacart_product_id) {');

        $this->assertNotFalse($reconcile, 'The push no longer reconciles at all.');
        $this->assertNotFalse($decide);
        $this->assertLessThan($decide, $reconcile,
            'The push decides create-versus-update before asking VentaCart, so it is guessing again.');
    }

    public function test_a_link_ventacart_cannot_confirm_is_dropped(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString("'state' => 'lost'", $body,
            'Nothing recognises a product that has been removed on VentaCart.');
        $this->assertStringContainsString('$link->delete();', $body,
            'A stale link survives reconciliation, so the next push sends an update VentaCart can only 404.');
    }

    public function test_every_divergence_has_a_verdict(): void
    {
        $body = $this->methodBody('reconcileLink');

        foreach (['confirmed', 'adopted', 'repointed', 'lost', 'new', 'blocked'] as $state) {
            $this->assertStringContainsString("'{$state}'", $body,
                "reconcileLink no longer reports '{$state}', so that divergence is handled silently or not at all.");
        }
    }

    public function test_a_claimed_id_is_refused_not_taken(): void
    {
        $body = $this->methodBody('reconcileLink');

        $this->assertStringContainsString('$this->linkProductTo(', $body,
            'Reconciling writes the link directly instead of through the guard that checks who holds it.');
        $this->assertStringContainsString("'state' => 'blocked'", $body);
    }

    public function test_the_row_always_offers_one_action_that_works(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString("'label' => 'Send to ' . \$storeName", $view,
            'The row lost its single always-available action.');

        foreach (['Push as new', 'Match VentaCart ID', 'Link to VentaCart again', 'Update on VentaCart'] as $gone) {
            $this->assertDoesNotMatchRegularExpression(
                '/>\s*'.preg_quote($gone, '/').'\s*<\/button>/',
                $view,
                "The row menu offers '{$gone}' again, which is one of the mutually exclusive states that made this a trap."
            );
        }
    }

    public function test_unlink_removes_the_link(): void
    {
        $body = $this->methodBody('unlinkProduct');

        $this->assertStringContainsString('VentaCartProductLink::where', $body,
            'Unlink still only flips a status flag, so the ERP goes on believing the product is on VentaCart.');
        $this->assertStringContainsString('->delete();', $body);
    }

    public function test_the_summary_states_the_finding_not_the_outcome(): void
    {
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString("' had been removed on VentaCart'", $source);
        $this->assertStringNotContainsString("'was recreated'", $source,
            'The summary claims an outcome the push may not have reached, so a failed recreate still reads as one.');
    }

    public function test_the_image_wall_is_explained(): void
    {
        $source = $this->source(self::SERVICE);

        $this->assertStringContainsString('images_unavailable', $source);
        $this->assertStringContainsString('will not create a product it cannot fetch images for', $source,
            'VentaCart refusing to create a product without fetchable images is shown as a raw envelope.');
        $this->assertStringContainsString('Nothing in the catalog needs fixing', $source,
            'The refusal does not tell the operator where the problem is not, so they will go hunting in the product.');
    }

    private function methodBody(string $method): string
    {
        $map = [
            'reconcileLink' => [\Extensions\ventacart\Services\VentaCart\VentaCartProductPush::class, self::SERVICE],
        ];
        [$class, $file] = $map[$method] ?? [VentaCartProductGroupController::class, self::CONTROLLER];
        $ref = new ReflectionMethod($class, $method);
        $lines = file(base_path($file));

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
