<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The product forms say out loud what they already say on screen.
 *
 * Measured before this work: 25 inputs in the variations grid with no
 * accessible name of any kind (192 on a realistic six-by-eight grid), the
 * built table headers with no scope, and across both forms zero
 * aria-describedby, zero aria-invalid and zero aria-required against seven
 * fields showing a visible "Required" marker. An operator using a screen
 * reader could not maintain the catalogue at all.
 *
 * These are source-contract assertions. The behaviour was verified in a
 * browser; what is held here is that the mechanisms remain, because each is
 * the kind of thing a later edit drops without noticing.
 */
class ProductFormAccessibilityTest extends TestCase
{
    private function grid(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    /**
     * Every cell in the variations grid names itself, by the row identity a
     * person would use out loud: "SKU for Small, Red".
     */
    public function test_every_variation_cell_has_an_accessible_name(): void
    {
        $source = $this->grid();

        $this->assertStringContainsString('function labelFor(', $source);

        foreach (['SKU', 'Quantity', 'Price', 'Cost'] as $column) {
            $this->assertStringContainsString("labelFor('".$column."'", $source,
                "the {$column} cell must carry an aria-label built from the row identity");
        }

        // Both grid shapes, not just the single-axis one.
        $this->assertStringContainsString("const rowName = [v1Name, v2Name].filter(Boolean).join(', ');", $source,
            'a combination row is identified by BOTH of its values');
    }

    /**
     * A column header never names a control inside a cell, but it is what
     * answers "which column am I in". The two hand-written tables on this
     * surface scope all of theirs; the built one had none.
     */
    public function test_the_built_headers_declare_their_scope(): void
    {
        $source = $this->grid();

        $this->assertStringContainsString("createEl('th', { className: cls, scope: 'col' }", $source);

        // No header may be built the old way, bypassing the helper.
        $this->assertStringNotContainsString("createEl('th', { className: 'fm-vcol-sku' }", $source);
    }

    /**
     * The shared field component gives its hint and error stable ids, which
     * is what the wiring has to find.
     */
    public function test_a_field_publishes_ids_for_its_hint_and_error(): void
    {
        $field = File::get(base_path('resources/views/components/ui/field.blade.php'));

        $this->assertStringContainsString('id="{{ $controlId }}-hint"', $field);
        $this->assertStringContainsString('id="{{ $controlId }}-error"', $field);
        $this->assertStringContainsString('data-required-marker', $field,
            'the required pill must be findable, or it stays decorative text');
    }

    /**
     * And something actually joins them up. The component takes its control
     * as an opaque slot and cannot reach into it, and its 49 callers pass
     * every kind of control, so the association is made once at runtime.
     */
    public function test_the_association_is_wired(): void
    {
        $js = File::get(resource_path('js/field-a11y.js'));

        $this->assertStringContainsString("setAttribute('aria-describedby'", $js);
        $this->assertStringContainsString("setAttribute('aria-invalid', 'true')", $js);
        $this->assertStringContainsString("setAttribute('aria-required', 'true')", $js);

        // It may only ever ADD. A control that declares its own state is the
        // author's decision and outranks this.
        $this->assertStringContainsString("!control.hasAttribute('aria-describedby')", $js);
        $this->assertStringContainsString("!control.hasAttribute('aria-required')", $js);

        $this->assertStringContainsString("import './field-a11y'", File::get(resource_path('js/app.js')));
    }

    /**
     * Summernote builds a role="textbox" with no name and ships
     * `outline: none` with nothing in its place - the one control on the form
     * with no focus ring, at the point where the typing happens.
     */
    public function test_the_rich_text_editor_is_named_and_keeps_a_focus_ring(): void
    {
        $wysiwyg = File::get(base_path('resources/views/catalog/products/partials/wysiwyg.blade.php'));

        $this->assertStringContainsString("editable.setAttribute('aria-label'", $wysiwyg);

        $css = File::get(resource_path('css/blotter-components.css'));

        // The selector has to out-specify the vendor's own three-class rule,
        // because the CDN stylesheet loads after our bundle and would
        // otherwise win on order.
        $this->assertStringContainsString(
            '.note-editor .note-editing-area .note-editable:focus',
            $css,
            'the ring must mirror the vendor chain, or it ties on specificity and loses on order'
        );
    }
}
