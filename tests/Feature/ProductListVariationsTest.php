<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The product list shows a product's variations under it.
 *
 * The legacy list did this and the redesign dropped it while KEEPING the
 * query that loads them - which is why the data was still there, batched and
 * unused, when it was restored. A parent that says only "5 variations" makes
 * you open the product to learn what they are and how many of each you hold,
 * which is the question a stock list exists to answer.
 *
 * Restored with the variation's own image, which the legacy row had a slot
 * for and never once filled: the controller hardcoded option_image = null, so
 * that branch could not fire. There was no per-variation image to put in it
 * until product_option_combinations gained one.
 */
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

        // Name, SKU, image and stock - the four things a stock list is for.
        $this->assertStringContainsString('$or->option_value_name', $view);
        $this->assertStringContainsString('$or->sku', $view);
        $this->assertStringContainsString('$or->option_image', $view);
        $this->assertStringContainsString('$or->quantity', $view);
    }

    /**
     * The controller stopped hardcoding null, which is what made the image
     * slot dead for as long as it existed.
     */
    public function test_the_controller_supplies_the_variation_image(): void
    {
        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));

        $this->assertStringNotContainsString('$c->option_image = null;', $controller,
            'the image slot was hardcoded null and never rendered');

        $this->assertStringContainsString(
            "\$c->option_image = trim((string) (\$c->image ?? '')) ?: null;",
            $controller
        );

        // It has to be selected, or the property does not exist to read.
        $this->assertStringContainsString("'c.absolute_price', 'c.image'", $controller);
    }

    /**
     * Stock carries the same tone rule as the parent row. A variation is
     * where an oversell actually lives, so it is the row that most needs to
     * say so.
     */
    public function test_variation_stock_is_toned_like_its_parent(): void
    {
        $this->assertStringContainsString(
            "\$orTone = \$orQty < 0 ? 'text-bad' : (\$orQty === 0 ? 'text-attn' : '');",
            $this->listView()
        );
    }

    /**
     * The per-product sales history says which channel sold each line. The
     * global order list always has; this page could not, so a table of sales
     * across four marketplaces named none of them.
     */
    public function test_per_product_sales_history_names_the_channel(): void
    {
        $view = File::get(base_path('resources/views/catalog/products/sales.blade.php'));

        $this->assertStringContainsString('>Channel</th>', $view);
        $this->assertStringContainsString('data-label="Channel"', $view);

        // Read the same way the Orders index reads it, so one order is
        // labelled identically on both pages.
        $this->assertStringContainsString("strtok(\$src, ':')", $view);

        $controller = File::get(base_path('app/Http/Controllers/Catalog/ProductController.php'));
        $this->assertStringContainsString("'o.marketplace_source',", $controller);
        $this->assertStringContainsString('availableMarketplaceSourceOptions()', $controller,
            'the label map must come from the registry, not a hardcoded list');
    }
}
