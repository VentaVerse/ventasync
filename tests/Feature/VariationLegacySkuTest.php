<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A missing SKU is only an error on a row somebody is making.
 *
 * The grid used to mark every blank SKU red and refuse the save, so a product
 * imported without variation SKUs could not be saved AT ALL: open it to
 * change one price and five fields nobody touched hold the form hostage, on a
 * rule the server never applied when the record was written. On a
 * six-by-eight product that is 48 SKUs invented before you can fix a typo.
 *
 * It also contradicted the rest of the form, where SKU is deliberately
 * optional. A row that ARRIVED blank keeps its right to be blank; a row being
 * created now does not, because that is the moment an operator can supply one.
 */
class VariationLegacySkuTest extends TestCase
{
    private function source(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    public function test_a_row_remembers_whether_it_arrived_without_a_sku(): void
    {
        $source = $this->source();

        // Both bootstrap paths stamp it: single-axis values and combinations.
        $this->assertSame(2, substr_count($source, 'legacyBlankSku: !String('),
            'both the value and the combination loaders must record provenance');

        // And it reaches the input the validator reads.
        $this->assertStringContainsString("skuOne.dataset.legacyBlank = '1'", $source);
        $this->assertStringContainsString("skuCombo.dataset.legacyBlank = '1'", $source);
    }

    public function test_a_legacy_blank_warns_and_a_new_blank_blocks(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            '/if \(skuInput\.dataset\.legacyBlank\) \{\s*legacyBlankCount\+\+;\s*\} else \{\s*skuInput\.classList\.add\(\'input-error\'\)/',
            $source,
            'a row that arrived blank must not be marked an error'
        );

        $this->assertStringContainsString('showOptNotice(', $source);
        $this->assertStringContainsString('will not map to a marketplace listing', $source);
    }

    /**
     * The flag must not survive the operator typing a SKU, or the row would
     * keep its exemption forever.
     */
    public function test_typing_a_sku_ends_the_exemption(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "opt1Values[i].legacyBlankSku = !!(skuInput && skuInput.dataset.legacyBlank)",
            $source
        );
        $this->assertStringContainsString("&& !(skuInput.value || '').trim();", $source);
    }

    /**
     * requestSubmit() does NOTHING while the form is already firing
     * submission events, and one of this listener's exits is synchronous -
     * it runs inside the very handler calling it. That path silently stopped
     * submitting when this moved off form.submit(), and only became
     * reachable once legacy rows could pass validation.
     */
    public function test_the_resubmit_leaves_the_current_dispatch_first(): void
    {
        $this->assertStringContainsString(
            'setTimeout(() => form.requestSubmit(), 0);',
            $this->source(),
            'a synchronous requestSubmit inside the submit handler is a no-op'
        );
    }

    /**
     * Every shape-changing control in this section asks now, not just the one
     * that destroys nothing.
     */
    public function test_all_three_shape_changing_controls_confirm(): void
    {
        $source = $this->source();

        foreach (['add-opt2-btn', 'remove-opt1-btn', 'remove-opt2-btn'] as $id) {
            $at = strpos($source, "getElementById('".$id."')");
            $this->assertNotFalse($at, $id.' not found');

            $this->assertStringContainsString(
                'window.confirmModal(',
                substr($source, $at, 1400),
                $id.' must confirm before it changes the shape of the grid'
            );
        }

        // The destructive ones name the count; a generic warning cannot tell
        // a mistake from a decision.
        $this->assertStringContainsString("' rows'", $source);
        $this->assertStringContainsString("' other combinations are'", $source);
    }
}
