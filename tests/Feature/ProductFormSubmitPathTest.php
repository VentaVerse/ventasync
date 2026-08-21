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

        $this->assertStringContainsString('form.requestSubmit();', $source,
            'requestSubmit fires the submit event; form.submit() does not');

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
}
