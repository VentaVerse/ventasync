<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductFormSubmitPathTest extends TestCase
{
    private function optionsSource(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    public function test_the_variations_module_resubmits_through_the_event(): void
    {
        $source = $this->optionsSource();

        $this->assertStringContainsString('form.requestSubmit()', $source,
            'requestSubmit fires the submit event; form.submit() does not');

        $this->assertStringContainsString('setTimeout(() => form.requestSubmit(), 0);', $source,
            'a synchronous requestSubmit inside the submit handler is a no-op');

        $this->assertStringContainsString('function submitPastThisListener()', $source);

        $this->assertSame(
            1,
            substr_count($source, 'form.submit();'),
            'the only remaining form.submit() is the documented pre-2020 fallback'
        );
    }

    public function test_the_resubmit_cannot_loop(): void
    {
        $source = $this->optionsSource();

        $this->assertStringContainsString('form._optionsValidated = true;', $source);
        $this->assertMatchesRegularExpression(
            '/if \(form\._optionsValidated\) \{\s*form\._optionsValidated = false;\s*return;/',
            $source,
            'the listener must clear the flag and return, not preventDefault'
        );
    }

    public function test_the_manufacturer_guard_is_retired(): void
    {
        $js = File::get(resource_path('js/pages/product-form.js'));

        $this->assertStringNotContainsString("err.textContent = 'Choose a manufacturer from the list.'", $js,
            'the guard is retired');

        $this->assertStringContainsString("inputId: 'manufacturer_search'", $js);
    }

    public function test_the_controller_still_treats_manufacturer_as_optional(): void
    {
        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));

        $this->assertStringContainsString("'manufacturer_id' => 'nullable|integer'", $controller);
    }

    public function test_every_editable_grid_field_is_serialised_into_the_payload(): void
    {
        $source = $this->optionsSource();

        $fields = [
            'js-sku' => 'sku',
            'js-qty' => 'quantity',
            'js-price' => 'absolute_price',
            'js-cost' => 'cost_amount',
            'js-image' => 'image',
            'js-enabled' => 'status',
        ];

        $missing = [];

        foreach ($fields as $class => $key) {
            if (! str_contains($source, $class)) {
                $missing[] = $class.' (control not built)';
                continue;
            }

            foreach (['values', 'combinations'] as $branch) {
                if (! str_contains($source, "'".$branch."[' + ")) {
                    continue;
                }

                $needle = "][".$key."]'";

                if (substr_count($source, $needle) < 2) {
                    $missing[] = $key.' (not serialised on both branches)';
                    break;
                }
            }
        }

        $this->assertSame([], array_unique($missing), sprintf(
            "These grid fields never reach the server: %s\n\n"
            ."A control the operator can change and the payload does not carry is a\n"
            ."feature that looks complete and does nothing. Add an addHidden for it in\n"
            ."beforeSubmit(), on the values branch AND the combinations branch.",
            implode(', ', array_unique($missing))
        ));
    }
}
