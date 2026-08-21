<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The variations grid must not lose stock and price when its shape changes.
 *
 * Adding a value to a second variation axis used to blank every existing SKU,
 * quantity and price, and Remove - the control a person reaches for to undo -
 * completed the damage rather than reversing it. The next click is Save, and
 * zero stock and zero price go to every marketplace.
 *
 * Two independent causes, both fixed:
 *
 *   1. renderTable() drew the combination grid from comboData, which is empty
 *      on a product that was one-axis, while the real values sat in
 *      opt1Values. Every cell rendered as zero and was then saved back.
 *
 *   2. saveTableToState() branched on `mode`, but every transition sets
 *      `mode` BEFORE renderTable() redraws - and renderTable() opens by
 *      calling saveTableToState(). So one call always harvested the DOM in
 *      the wrong shape, reading a 10-row combination grid into 5 single
 *      values by flat row index.
 *
 * This is a source-contract test. The behaviour itself is JavaScript and was
 * verified in a browser; what is asserted here is that the two mechanisms
 * that make it correct are still present, because both are the kind of thing
 * a later refactor removes without noticing.
 */
class VariationGridStateTest extends TestCase
{
    private function source(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

    /**
     * The DOM is harvested in the shape it was DRAWN in, never the shape the
     * state has just been switched to.
     */
    public function test_the_grid_is_read_back_in_the_shape_it_was_drawn(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('let renderedMode', $source,
            'the shape the table is drawn in must be tracked separately from `mode`');

        $this->assertMatchesRegularExpression(
            '/function saveTableToState\(\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*if \(renderedMode === \'one\'\)/',
            $source,
            'saveTableToState must branch on renderedMode; branching on `mode` reads a '
            .'combination grid as single values by flat row index'
        );

        $this->assertStringContainsString("} else if (renderedMode === 'two') {", $source);

        // renderTable sets it last, so it describes what is on screen.
        $this->assertStringContainsString("renderedMode = is2 ? 'two' : 'one';", $source);
    }

    /**
     * Converting one axis into two carries the existing values into the first
     * column instead of rendering a grid of zeros over them.
     */
    public function test_converting_to_two_axes_seeds_the_first_column(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('function seedFirstColumnFromOneAxis()', $source);

        // Only on the transition. A product that arrives with combinations
        // already loaded from the server must not be overwritten.
        $this->assertMatchesRegularExpression(
            '/function seedFirstColumnFromOneAxis\(\)\s*\{\s*if \(Object\.keys\(comboData\)\.length > 0\) return;/',
            $source,
            'the seed must no-op when comboData already holds server-loaded combinations'
        );

        // Called before the first second-axis value turns the grid into
        // combinations, which is the moment the data would otherwise vanish.
        $this->assertMatchesRegularExpression(
            '/if \(opt2Values\.length === 0\) \{\s*saveTableToState\(\);\s*seedFirstColumnFromOneAxis\(\);/',
            $source
        );
    }

    /**
     * Removing the second axis may not write an empty combination over a
     * value that still has data. This is what made the loss permanent.
     */
    public function test_removing_the_second_axis_cannot_erase_a_value_that_has_data(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('const firstIsEmpty =', $source);
        $this->assertStringContainsString('const valueHasData =', $source);
        $this->assertStringContainsString('if (firstIsEmpty && valueHasData) return;', $source);
    }

    /**
     * The conversion says what it is about to do. Every other destructive or
     * shape-changing control on this surface goes through the shared dialog;
     * this section had none on any of its three.
     */
    public function test_adding_a_second_axis_asks_first(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('window.confirmModal(', $source,
            'the conversion must go through the shared confirm dialog');

        $this->assertStringContainsString('become combinations of the two', $source,
            'the dialog must name what happens, not just ask');
    }
}
