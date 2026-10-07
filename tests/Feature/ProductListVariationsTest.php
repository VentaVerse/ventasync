<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductListVariationsTest extends TestCase
{
    private function listView(): string
    {
        return File::get(base_path('resources/views/catalog/products/index.blade.php'));
    }

    public function test_the_list_renders_a_row_per_variation(): void
    {
        $view = $this->listView();

        $this->assertStringContainsString('@foreach($p->option_rows ?? [] as $or)', $view);
        $this->assertStringContainsString('<tr class="bl-varrow">', $view);

        $this->assertStringContainsString('$or->option_value_name', $view);
        $this->assertStringContainsString('$or->sku', $view);
        $this->assertStringContainsString('$or->option_image', $view);
        $this->assertStringContainsString('$or->quantity', $view);
    }

    public function test_the_controller_supplies_the_variation_image(): void
    {
        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));

        $this->assertStringNotContainsString('$c->option_image = null;', $controller,
            'the image slot was hardcoded null and never rendered');

        $this->assertStringContainsString('VariationRows::forProducts', $controller,
            'the core list must build its variation rows through the shared query');

        $helper = File::get(base_path('app/Support/VariationRows.php'));

        $this->assertStringContainsString(
            "\$c->option_image = trim((string) (\$c->image ?? '')) ?: null;",
            $helper
        );

        $this->assertStringContainsString("'c.absolute_price', 'c.image'", $helper);
    }

    public function test_variation_stock_is_toned_like_its_parent(): void
    {
        $this->assertStringContainsString(
            "\$orTone = \$orQty < 0 ? 'text-bad' : (\$orQty === 0 ? 'text-attn' : '');",
            $this->listView()
        );
    }

    public function test_per_product_sales_history_names_the_channel(): void
    {
        $view = File::get(base_path('resources/views/catalog/products/sales.blade.php'));

        $this->assertStringContainsString('>Channel</th>', $view);
        $this->assertStringContainsString('data-label="Channel"', $view);

        $this->assertStringContainsString("strtok(\$src, ':')", $view);

        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));
        $this->assertStringContainsString("'o.marketplace_source',", $controller);
        $this->assertStringContainsString('availableMarketplaceSourceOptions()', $controller,
            'the label map must come from the registry, not a hardcoded list');
    }
}
