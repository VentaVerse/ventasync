<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VariationLegacySkuTest extends TestCase
{
    private function source(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    public function test_a_row_remembers_whether_it_arrived_without_a_sku(): void
    {
        $source = $this->source();

        $this->assertSame(2, substr_count($source, 'legacyBlankSku: !String('),
            'both the value and the combination loaders must record provenance');

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

    public function test_typing_a_sku_ends_the_exemption(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "opt1Values[i].legacyBlankSku = !!(skuInput && skuInput.dataset.legacyBlank)",
            $source
        );
        $this->assertStringContainsString("&& !(skuInput.value || '').trim();", $source);
    }

    public function test_the_resubmit_leaves_the_current_dispatch_first(): void
    {
        $this->assertStringContainsString(
            'setTimeout(() => form.requestSubmit(), 0);',
            $this->source(),
            'a synchronous requestSubmit inside the submit handler is a no-op'
        );
    }

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

        $this->assertStringContainsString("' rows'", $source);
        $this->assertStringContainsString("' other combinations are'", $source);
    }
}
