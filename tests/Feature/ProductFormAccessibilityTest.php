<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductFormAccessibilityTest extends TestCase
{
    private function grid(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    public function test_every_variation_cell_has_an_accessible_name(): void
    {
        $source = $this->grid();

        $this->assertStringContainsString('function labelFor(', $source);

        foreach (['SKU', 'Quantity', 'Price', 'Cost'] as $column) {
            $this->assertStringContainsString("labelFor('".$column."'", $source,
                "the {$column} cell must carry an aria-label built from the row identity");
        }

        $this->assertStringContainsString("const rowName = [v1Name, v2Name].filter(Boolean).join(', ');", $source,
            'a combination row is identified by BOTH of its values');
    }

    public function test_the_built_headers_declare_their_scope(): void
    {
        $source = $this->grid();

        $this->assertStringContainsString("createEl('th', { className: cls, scope: 'col' }", $source);

        $this->assertStringNotContainsString("createEl('th', { className: 'fm-vcol-sku' }", $source);
    }

    public function test_a_field_publishes_ids_for_its_hint_and_error(): void
    {
        $field = File::get(base_path('resources/views/components/ui/field.blade.php'));
        $hint = File::get(base_path('resources/views/components/ui/hint.blade.php'));

        $this->assertStringContainsString(":bubble-id=\"\$controlId ? \$controlId . '-hint' : null\"", $field);
        $this->assertStringContainsString('id="{{ $hintId }}"', $hint);
        $this->assertStringContainsString('id="{{ $controlId }}-error"', $field);
        $this->assertStringContainsString('data-required-marker', $field,
            'the required pill must be findable, or it stays decorative text');
    }

    public function test_the_association_is_wired(): void
    {
        $js = File::get(resource_path('js/field-a11y.js'));

        $this->assertStringContainsString("setAttribute('aria-describedby'", $js);
        $this->assertStringContainsString("setAttribute('aria-invalid', 'true')", $js);
        $this->assertStringContainsString("setAttribute('aria-required', 'true')", $js);

        $this->assertStringContainsString("!control.hasAttribute('aria-describedby')", $js);
        $this->assertStringContainsString("!control.hasAttribute('aria-required')", $js);

        $this->assertStringContainsString("import './field-a11y'", File::get(resource_path('js/app.js')));
    }

    public function test_the_rich_text_editor_is_named_and_keeps_a_focus_ring(): void
    {
        $wysiwyg = File::get(base_path('resources/views/catalog/products/partials/wysiwyg.blade.php'));

        $this->assertStringContainsString("editable.setAttribute('aria-label'", $wysiwyg);

        $css = File::get(resource_path('css/blotter-components.css'));

        $this->assertStringContainsString(
            '.note-editor .note-editing-area .note-editable:focus',
            $css,
            'the ring must mirror the vendor chain, or it ties on specificity and loses on order'
        );
    }
}
