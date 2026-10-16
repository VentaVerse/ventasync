<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductListVariationsTest extends TestCase
{
    use RefreshDatabase;

    private function listView(): string
    {
        return File::get(base_path('resources/views/catalog/products/index.blade.php'));
    }

    public function test_the_list_renders_a_row_per_variation(): void
    {
        $view = $this->listView();

        $this->assertStringContainsString('@foreach($p->variation_rows ?? [] as $or)', $view);
        $this->assertStringContainsString('<tr class="bl-varrow">', $view);

        $this->assertStringContainsString('$or->variation_pairs', $view);
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
        $this->assertStringContainsString("'o.channel',", $controller);
        $this->assertStringContainsString('availableChannelOptions()', $controller,
            'the label map must come from the registry, not a hardcoded list');
    }

    public function test_a_variation_row_reads_type_and_value_then_sku(): void
    {
        $lang = (int) config('catalog.default_language_id');
        $productId = (int) DB::table('products')->insertGetId([
            'sku' => 'valeton-gp-200', 'quantity' => 4, 'price' => 21990, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => $lang,
            'name' => 'Valeton GP-200', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        $typeId = (int) DB::table('variation_types')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table('variation_type_descriptions')->insert(['variation_type_id' => $typeId, 'language_id' => $lang, 'name' => 'Color/Finish']);
        $valueId = (int) DB::table('variation_values')->insertGetId(['variation_type_id' => $typeId, 'image' => '', 'sort_order' => 0]);
        DB::table('variation_value_descriptions')->insert(['variation_value_id' => $valueId, 'language_id' => $lang, 'variation_type_id' => $typeId, 'name' => 'Red']);
        $ptId = (int) DB::table('product_variation_types')->insertGetId(['product_id' => $productId, 'variation_type_id' => $typeId, 'value' => '', 'required' => 1]);
        $povId = (int) DB::table('product_variation_values')->insertGetId([
            'product_variation_type_id' => $ptId, 'product_id' => $productId, 'variation_type_id' => $typeId,
            'variation_value_id' => $valueId, 'sku' => '', 'quantity' => 4, 'subtract_stock' => 1,
            'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
        ]);
        $comboId = (int) DB::table('product_variations')->insertGetId([
            'product_id' => $productId, 'sku' => 'valeton-gp-200r', 'image' => '', 'status' => 1,
            'quantity' => 4, 'absolute_price' => 21990, 'absolute_cost' => 0, 'subtract_stock' => 1, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_variation_parts')->insert(['product_variation_id' => $comboId, 'product_variation_value_id' => $povId]);

        $group = UserGroup::create(['name' => 'Catalog list']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_catalog/product', 'manage_catalog/product'])->pluck('id')->all());

        $this->actingAs(User::factory()->create(['user_group_id' => $group->id]))
            ->get('/catalog/products')
            ->assertOk()
            ->assertSeeInOrder(['co-var__name">Color/Finish', 'co-var__value">Red', 'valeton-gp-200r'], false);
    }
}
