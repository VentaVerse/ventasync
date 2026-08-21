<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A product form with variations must submit through the submit EVENT.
 *
 * The options module validated the grid, prevented the default, ran an async
 * SKU check and then called form.submit() - which submits without firing the
 * submit event. Every other submit listener on the form was therefore
 * skipped, on every product that has variations.
 *
 * The manufacturer guard was the visible casualty: it printed "Choose a
 * manufacturer from the list" in red AND the form saved anyway, in the same
 * click. Verified in a browser before the fix: the page navigated away and no
 * other listener ran at all, so nothing could even preventDefault it.
 *
 * That guard is now retired for its own reasons, but the hazard was never
 * really about the guard - it was that a form could route around its own
 * validation silently, and anything added later would have been skipped the
 * same way with nothing to say so.
 */
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

        /* And it has to leave the current dispatch first. requestSubmit() is
           specified to do NOTHING while the form is already firing
           submission events, and one of this listener's three exits is
           synchronous - it runs inside the very handler calling it. That
           path silently stopped submitting when this moved off form.submit(),
           and stayed hidden until legacy rows could reach it. */
        $this->assertStringContainsString('setTimeout(() => form.requestSubmit(), 0);', $source,
            'a synchronous requestSubmit inside the submit handler is a no-op');

        // One way out, so a later edit cannot reintroduce a direct submit at
        // one of the three exits this listener has.
        $this->assertStringContainsString('function submitPastThisListener()', $source);

        $this->assertSame(
            1,
            substr_count($source, 'form.submit();'),
            'the only remaining form.submit() is the documented pre-2020 fallback'
        );
    }

    /**
     * requestSubmit re-enters this very listener, so it needs a way to stand
     * aside - the same shape confirm-modal.js uses for the same reason.
     */
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

    /**
     * The guard that used to be bypassed is gone, and for a stated reason
     * rather than by accident: its only justification was that the image
     * library filed by manufacturer, which it no longer does.
     */
    public function test_the_manufacturer_guard_is_retired(): void
    {
        $js = File::get(resource_path('js/pages/product-form.js'));

        $this->assertStringNotContainsString("err.textContent = 'Choose a manufacturer from the list.'", $js,
            'the guard is retired');

        // The typeahead itself stays - choosing a manufacturer is still useful.
        $this->assertStringContainsString("inputId: 'manufacturer_search'", $js);
    }

    /**
     * And the server never required it either, which is half of why the
     * guard was wrong to exist.
     */
    public function test_the_controller_still_treats_manufacturer_as_optional(): void
    {
        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));

        $this->assertStringContainsString("'manufacturer_id' => 'nullable|integer'", $controller);
    }

    /**
     * EVERY FIELD THE GRID EDITS REACHES THE PAYLOAD.
     *
     * This is the gap that shipped two dead features. The variation image and
     * the variation status were both persisted correctly by the controller,
     * covered by tests that POST to the endpoint, and green - while the FORM
     * never sent the field at all. An endpoint test cannot see that; it
     * supplies the payload itself.
     *
     * The grid edits its rows through a fixed set of input classes and
     * serialises them into hidden inputs at submit. Anything in that set with
     * no matching addHidden is a control the operator can change and the
     * server will never hear about.
     */
    public function test_every_editable_grid_field_is_serialised_into_the_payload(): void
    {
        $source = $this->optionsSource();

        // class in the grid => key in the payload
        $fields = [
            'js-sku' => 'sku',
            'js-qty' => 'quantity',
            'js-price' => 'absolute_price',
            'js-cost' => 'absolute_cost',
            'js-image' => 'image',
            'js-enabled' => 'status',
        ];

        $missing = [];

        foreach ($fields as $class => $key) {
            // The control has to exist in the grid at all.
            if (! str_contains($source, $class)) {
                $missing[] = $class.' (control not built)';
                continue;
            }

            // And be serialised on BOTH branches: a single-axis product and a
            // combination grid go through different loops.
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
