<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeListingTemplate;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductGroupPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        ShopeeStore::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);
    }

    private function storeId(): int
    {
        return (int) ShopeeStore::defaultStore()->id;
    }

    private function user(array $keys = ['view_shopee/product', 'manage_shopee/product', 'view_shopee/product_group', 'manage_shopee/product_group', 'view_shopee/listing_template', 'manage_shopee/listing_template']): User
    {
        $group = UserGroup::create(['name' => 'Group page ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $name, string $sku): int
    {
        $pid = DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function member(ShopeeProductGroup $group, int $pid): void
    {
        DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);
    }

    public function test_the_group_page_lists_its_products_its_template_and_the_add_panel(): void
    {
        $template = ShopeeListingTemplate::create(['store_id' => $this->storeId(), 'name' => 'Pedals, 10% up', 'markup_percent' => 10]);
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $group->forceFill(['listing_template_id' => $template->id])->save();
        $pid = $this->product('Fuzz pedal', 'FUZZ-1');
        $this->member($group, $pid);

        $this->actingAs($this->user())->get(route('ext.shopee.product-groups.edit', ['id' => $group->id]))
            ->assertOk()
            ->assertSee('Fuzz pedal')
            ->assertSee('FUZZ-1')
            ->assertSee('Products in this group')
            ->assertSee('Apply to 1 product')
            ->assertSee('data-add-panel-open', false)
            ->assertSee('data-add-panel-group value="' . $group->id . '"', false)
            ->assertSee('name="product_ids[]" value="' . $pid . '"', false)
            ->assertSee(e(route('ext.shopee.listings.edit', ['productId' => $pid])), false);

        $html = $this->actingAs($this->user())->get(route('ext.shopee.product-groups.edit', ['id' => $group->id]))->getContent();
        $edit = e(route('ext.shopee.listing-templates.edit', ['template' => $template->id]));
        $this->assertMatchesRegularExpression('~<select[^>]*data-pg-template~', $html, 'the dropdown carries the hook');
        $this->assertMatchesRegularExpression('~<a href="' . preg_quote($edit, '~') . '"[^>]*target="_blank"[^>]*data-pg-template-view~', $html, 'View template opens the chosen template in a new tab');
        $this->assertStringContainsString('data-url="' . $edit . '"', $html);

        $this->actingAs($this->user(['view_shopee/product', 'view_shopee/product_group']))->get(route('ext.shopee.product-groups.edit', ['id' => $group->id]))
            ->assertOk()->assertDontSee('data-pg-template-view', false);

        $this->actingAs($this->user())->get(route('ext.shopee.product-groups.index'))
            ->assertOk()->assertSee('Pedals, 10% up')
            ->assertSee(e(route('ext.shopee.product-groups.edit', ['id' => $group->id])), false);
    }

    public function test_the_group_keeps_a_template_of_its_own_store_only(): void
    {
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $mine = ShopeeListingTemplate::create(['store_id' => $this->storeId(), 'name' => 'Mine']);
        $outlet = ShopeeStore::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '3', 'access_token' => 't', 'refresh_token' => 'r', 'store_name' => 'Outlet']);
        $other = ShopeeListingTemplate::create(['store_id' => $outlet->id, 'name' => 'Other store']);

        $this->actingAs($this->user())->put(route('ext.shopee.product-groups.update', ['id' => $group->id]), ['name' => 'Pedals', 'listing_template_id' => $other->id])
            ->assertSessionHasErrors('listing_template_id');
        $this->actingAs($this->user())->put(route('ext.shopee.product-groups.update', ['id' => $group->id]), ['name' => 'Pedals', 'listing_template_id' => $mine->id])
            ->assertRedirect(route('ext.shopee.product-groups.edit', ['id' => $group->id]));

        $this->assertSame($mine->id, (int) $group->fresh()->listing_template_id);
    }

    public function test_the_search_says_where_each_product_stands(): void
    {
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $other = ShopeeProductGroup::create(['name' => 'Cables']);
        $in = $this->product('Pedal in group', 'P-IN');
        $elsewhere = $this->product('Pedal in cables', 'P-ELSE');
        $fresh = $this->product('Pedal on no store', 'P-NEW');
        $this->member($group, $in);
        $this->member($other, $elsewhere);
        ShopeeListing::query()->create(['product_id' => $elsewhere]);

        $items = collect($this->actingAs($this->user())->getJson(route('ext.shopee.product-groups.search', ['id' => $group->id, 'q' => 'Pedal', 'all' => 1]))
            ->assertOk()->json('items'))->keyBy('id');

        $this->assertTrue($items[$in]['on_store']);
        $this->assertSame('Cables', $items[$elsewhere]['elsewhere']);
        $this->assertTrue($items[$elsewhere]['listed']);
        $this->assertFalse($items[$fresh]['listed']);
        $this->assertNull($items[$fresh]['elsewhere']);

        $hidden = collect($this->actingAs($this->user())->getJson(route('ext.shopee.product-groups.search', ['id' => $group->id, 'q' => 'Pedal']))->json('items'))->pluck('id')->all();
        $this->assertNotContains($in, $hidden, 'members are left out unless asked for');
    }

    public function test_adding_from_the_group_page_lists_the_product_and_moves_it_from_its_old_group(): void
    {
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $other = ShopeeProductGroup::create(['name' => 'Cables']);
        $pid = $this->product('Delay pedal', 'DLY-1');
        $this->member($other, $pid);

        $this->actingAs($this->user())->postJson(route('ext.shopee.products.add_to_store', ['productId' => $pid]), ['group' => $group->id, 'move' => true])
            ->assertOk();

        $this->assertSame([$group->id], DB::table('ch_shopee_product_group_products')->where('product_id', $pid)->pluck('shopee_product_group_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue(ShopeeListing::query()->where('product_id', $pid)->exists());
    }

    public function test_remove_takes_the_product_out_and_keeps_its_listing(): void
    {
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $pid = $this->product('Chorus pedal', 'CHR-1');
        $this->member($group, $pid);
        ShopeeListing::query()->create(['product_id' => $pid, 'markup_percent' => 12]);

        $this->actingAs($this->user())->post(route('ext.shopee.product-groups.remove', ['id' => $group->id]), ['ids' => [$pid]])
            ->assertRedirect(route('ext.shopee.product-groups.edit', ['id' => $group->id]));

        $this->assertFalse(DB::table('ch_shopee_product_group_products')->where('product_id', $pid)->exists());
        $this->assertSame(12.0, (float) ShopeeListing::query()->where('product_id', $pid)->value('markup_percent'));
    }

    public function test_a_reader_cannot_change_the_group(): void
    {
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $pid = $this->product('Chorus pedal', 'CHR-1');
        $this->member($group, $pid);
        $reader = $this->user(['view_shopee/product', 'view_shopee/product_group']);

        $this->actingAs($reader)->get(route('ext.shopee.product-groups.edit', ['id' => $group->id]))
            ->assertOk()->assertSee('Chorus pedal')->assertDontSee('data-add-panel-open', false);
        $this->actingAs($reader)->post(route('ext.shopee.product-groups.remove', ['id' => $group->id]), ['ids' => [$pid]]);
        $this->assertTrue(DB::table('ch_shopee_product_group_products')->where('product_id', $pid)->exists());
    }

    public function test_applying_from_the_group_returns_to_the_group(): void
    {
        $template = ShopeeListingTemplate::create(['store_id' => $this->storeId(), 'name' => 'Pedals, 10% up', 'markup_percent' => 10]);
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $pid = $this->product('Fuzz pedal', 'FUZZ-1');
        $this->member($group, $pid);
        ShopeeListing::query()->create(['product_id' => $pid]);
        $back = route('ext.shopee.product-groups.edit', ['id' => $group->id], false);

        $this->actingAs($this->user())->post(route('ext.shopee.listing-templates.preview'), ['template_id' => $template->id, 'product_ids' => [$pid], '_return' => $back])
            ->assertOk()->assertSee('name="_return" value="' . $back . '"', false)->assertSee('Product group');

        $this->actingAs($this->user())->post(route('ext.shopee.listing-templates.apply'), ['template_id' => $template->id, 'product_ids' => [$pid], '_return' => $back])
            ->assertRedirect($back);
        $this->assertSame(10.0, (float) ShopeeListing::query()->where('product_id', $pid)->value('markup_percent'));
    }

    public function test_the_templates_page_is_a_list_and_a_template_in_use_is_not_deleted(): void
    {
        $used = ShopeeListingTemplate::create(['store_id' => $this->storeId(), 'name' => 'Pedals, 10% up', 'markup_percent' => 10]);
        $free = ShopeeListingTemplate::create(['store_id' => $this->storeId(), 'name' => 'Cables, 5% up']);
        $group = ShopeeProductGroup::create(['name' => 'Pedals']);
        $group->forceFill(['listing_template_id' => $used->id])->save();

        $this->actingAs($this->user())->get(route('ext.shopee.listing-templates.index'))
            ->assertOk()
            ->assertSee('Pedals, 10% up')->assertSee('Cables, 5% up')
            ->assertSee('+10%')
            ->assertSee('1 product group')
            ->assertSee(e(route('ext.shopee.listing-templates.edit', ['template' => $free->id])), false)
            ->assertDontSee('data-nav-select', false);

        $this->actingAs($this->user())->get(route('ext.shopee.listing-templates.edit', ['template' => $used->id]))
            ->assertOk()->assertDontSee('data-nav-select', false)->assertSee('Used by')
            ->assertSee(e(route('ext.shopee.product-groups.edit', ['id' => $group->id])), false);

        $this->actingAs($this->user())->delete(route('ext.shopee.listing-templates.destroy', ['template' => $used->id]))
            ->assertRedirect(route('ext.shopee.listing-templates.index'))
            ->assertSessionHas('error', 'Not deleted. "Pedals, 10% up" is used by 1 product group.');
        $this->assertNotNull($used->fresh());

        $this->actingAs($this->user())->delete(route('ext.shopee.listing-templates.destroy', ['template' => $free->id]))
            ->assertRedirect(route('ext.shopee.listing-templates.index'));
        $this->assertNull($free->fresh());
    }

    public function test_a_description_template_or_watermark_in_use_is_not_deleted(): void
    {
        $keys = ['view_shopee/product', 'manage_shopee/product', 'view_shopee/description_template', 'manage_shopee/description_template', 'view_shopee/watermark_template', 'manage_shopee/watermark_template', 'view_shopee/listing_template', 'manage_shopee/listing_template'];
        $store = $this->storeId();
        $prefix = \App\Models\DescriptionTemplate::create(['channel' => 'shopee', 'store_id' => $store, 'name' => 'Shipping notice', 'body' => 'Ships in 2 days']);
        $suffix = \App\Models\DescriptionTemplate::create(['channel' => 'shopee', 'store_id' => $store, 'name' => 'Warranty', 'body' => '1 year']);
        $spare = \App\Models\DescriptionTemplate::create(['channel' => 'shopee', 'store_id' => $store, 'name' => 'Spare', 'body' => '']);
        $pid = $this->product('Fuzz pedal', 'FUZZ-1');
        ShopeeListing::query()->create(['product_id' => $pid, 'description_prefix_id' => $prefix->id, 'description_suffix_id' => $suffix->id]);

        $this->actingAs($this->user($keys))->get(route('ext.shopee.description-templates.index'))
            ->assertOk()->assertSee('1 listing')->assertSee('Not used');
        $this->actingAs($this->user($keys))->delete(route('ext.shopee.description-templates.destroy', ['template' => $suffix->id]))
            ->assertSessionHas('error', 'Not deleted. "Warranty" is used by 1 listing.');
        $this->assertNotNull($suffix->fresh());
        $this->actingAs($this->user($keys))->delete(route('ext.shopee.description-templates.destroy', ['template' => $spare->id]))
            ->assertSessionHas('status');
        $this->assertNull($spare->fresh());

        $mark = \App\Models\WatermarkTemplate::query()->forceCreate(['channel' => 'shopee', 'store_id' => $store, 'name' => 'Logo', 'image_path' => 'catalog/x.png', 'position' => 'bottom-right', 'size_percent' => 18, 'offset_x_percent' => 0, 'offset_y_percent' => 0, 'opacity' => 1]);
        ShopeeListingTemplate::create(['store_id' => $store, 'name' => 'Pedals', 'watermark_template_id' => $mark->id]);
        $this->actingAs($this->user($keys))->get(route('ext.shopee.watermarks.index'))
            ->assertOk()->assertSee('1 listing template');
        $this->actingAs($this->user($keys))->delete(route('ext.shopee.watermarks.destroy', ['template' => $mark->id]))
            ->assertSessionHas('error', 'Not deleted. "Logo" is used by 1 listing template.');
        $this->assertNotNull($mark->fresh());
    }
}
