<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VariationGridStateTest extends TestCase
{
    private function source(): string
    {
        return File::get(base_path('resources/views/catalog/products/partials/options_fields.blade.php'));
    }

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

        $this->assertStringContainsString("renderedMode = is2 ? 'two' : 'one';", $source);
    }

    public function test_converting_to_two_axes_seeds_the_first_column(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('function seedFirstColumnFromOneAxis()', $source);

        $this->assertMatchesRegularExpression(
            '/function seedFirstColumnFromOneAxis\(\)\s*\{\s*if \(Object\.keys\(comboData\)\.length > 0\) return;/',
            $source,
            'the seed must no-op when comboData already holds server-loaded combinations'
        );

        $this->assertMatchesRegularExpression(
            '/if \(opt2Values\.length === 0\) \{\s*saveTableToState\(\);\s*seedFirstColumnFromOneAxis\(\);/',
            $source
        );
    }

    public function test_removing_the_second_axis_cannot_erase_a_value_that_has_data(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('const firstIsEmpty =', $source);
        $this->assertStringContainsString('const valueHasData =', $source);
        $this->assertStringContainsString('if (firstIsEmpty && valueHasData) return;', $source);
    }

    public function test_adding_a_second_axis_asks_first(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('window.confirmModal(', $source,
            'the conversion must go through the shared confirm dialog');

        $this->assertStringContainsString('become combinations of the two', $source,
            'the dialog must name what happens, not just ask');
    }
}
