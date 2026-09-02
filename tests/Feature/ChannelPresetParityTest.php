<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaProductGroupAttribute;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Presets earn their name on Lazada and TikTok (#195). The owner's
 * acceptance, verbatim: open a channel's Listings, select products,
 * start a push, and get a preset's settings without ever visiting the
 * Presets page. The trio per channel: Start from a preset on the
 * listing form, Apply preset on the bulk bar, Save as preset on save.
 */
class ChannelPresetParityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Preset parity ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => '', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Preset product ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    // ── Lazada ───────────────────────────────────────────────────────

    public function test_the_lazada_form_starts_from_a_preset(): void
    {
        $preset = LazadaProductGroup::create(['name' => 'Everyday preset', 'lazada_category_id' => 10001, 'markup_percent' => 20]);
        LazadaProductGroupAttribute::create(['lazada_product_group_id' => $preset->id, 'attribute_key' => 'material', 'value' => 'Alloy']);
        $pid = $this->seedProduct('LZP-1');

        $page = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->get(route('ext.lazada.products.create', ['product_id' => $pid, 'preset' => $preset->id]))
            ->assertOk();

        $page->assertSee('Loaded "Everyday preset"', false);
        $page->assertSee('value="10001"', false);
    }

    public function test_saving_a_lazada_listing_can_save_the_preset_too(): void
    {
        $pid = $this->seedProduct('LZP-2');

        $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->post(route('ext.lazada.products.store'), [
                'product_id' => $pid,
                'primary_category_id' => 10099,
                'markup_percent' => 15,
                'preset_save_name' => 'Saved from the form',
            ]);

        $preset = LazadaProductGroup::query()->where('name', 'Saved from the form')->first();
        $this->assertNotNull($preset, 'saving the listing with a preset name must create the preset');
        $this->assertSame(10099, (int) $preset->lazada_category_id);
        $this->assertSame(15.0, (float) $preset->markup_percent);
    }

    public function test_the_lazada_bulk_bar_applies_a_preset_to_the_selection(): void
    {
        $preset = LazadaProductGroup::create(['name' => 'Bulk dress', 'lazada_category_id' => 10777, 'markup_fixed' => 25]);
        LazadaProductGroupAttribute::create(['lazada_product_group_id' => $preset->id, 'attribute_key' => 'warranty_type', 'value' => 'Local seller warranty']);
        $a = LazadaProduct::create(['product_id' => $this->seedProduct('LZP-3')]);
        $b = LazadaProduct::create(['product_id' => $this->seedProduct('LZP-4'), 'primary_category_id' => 1]);

        $r = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->post(route('ext.lazada.products.bulk_apply_preset'), [
                'preset_group_id' => $preset->id,
                'listing_ids' => [$a->id, $b->id],
            ]);

        $r->assertSessionHas('status');
        $this->assertStringContainsString("Preset 'Bulk dress' applied to 2 listings", session('status'));
        $this->assertSame(10777, (int) $a->fresh()->primary_category_id);
        $this->assertSame(10777, (int) $b->fresh()->primary_category_id, 'apply means apply - the preset overwrites, and the confirm dialog said so');
        $this->assertSame('Local seller warranty', LazadaProductAttribute::query()
            ->where('lazada_product_id', $a->id)->where('attribute_key', 'warranty_type')->value('value'));
    }

    // ── TikTok ───────────────────────────────────────────────────────

    public function test_the_tiktok_listing_page_starts_from_a_preset(): void
    {
        \Extensions\tiktok\Models\TikTokCategory::create(['id' => 'cat-9', 'name' => 'Guitar Parts', 'is_leaf' => true]);
        $preset = TikTokProductGroup::create(['name' => 'TT preset', 'tiktok_category_id' => 'cat-9', 'markup_percent' => 10]);
        $pid = $this->seedProduct('TTP-1');

        $page = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product', 'manage_tiktok/listing', 'view_tiktok/listing']))
            ->get(route('ext.tiktok.listings.edit', ['productId' => $pid, 'preset' => $preset->id]))
            ->assertOk();

        $page->assertSee('Loaded "TT preset"', false);
    }

    public function test_saving_a_tiktok_listing_can_save_the_preset_too(): void
    {
        \Extensions\tiktok\Models\TikTokCategory::create(['id' => 'cat-5', 'name' => 'Cables', 'is_leaf' => true]);
        $pid = $this->seedProduct('TTP-2');

        $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product', 'manage_tiktok/listing', 'view_tiktok/listing']))
            ->put(route('ext.tiktok.listings.update', $pid), [
                'tiktok_category_id' => 'cat-5',
                'markup_fixed' => 50,
                'preset_save_name' => 'TT saved preset',
            ]);

        $preset = TikTokProductGroup::query()->where('name', 'TT saved preset')->first();
        $this->assertNotNull($preset);
        $this->assertSame('cat-5', (string) $preset->tiktok_category_id);
        $this->assertSame(50.0, (float) $preset->markup_fixed);
    }

    public function test_the_tiktok_bulk_bar_applies_a_preset_and_creates_missing_records(): void
    {
        $preset = TikTokProductGroup::create(['name' => 'TT bulk', 'tiktok_category_id' => 'cat-1', 'markup_percent' => 12]);
        $withRow = $this->seedProduct('TTP-3');
        TikTokListing::create(['product_id' => $withRow]);
        $without = $this->seedProduct('TTP-4');

        $r = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->post(route('ext.tiktok.products.bulk_apply_preset'), [
                'preset_group_id' => $preset->id,
                'product_ids' => [$withRow, $without],
            ]);

        $r->assertSessionHas('status');
        $this->assertSame('cat-1', (string) TikTokListing::query()->where('product_id', $withRow)->value('tiktok_category_id'));
        $this->assertSame('cat-1', (string) TikTokListing::query()->where('product_id', $without)->value('tiktok_category_id'),
            'a product with no listing record gets one - that is what makes Send just work after Apply');
    }

    /** the owner's acceptance: the workbench offers it all; the Presets page is optional. */
    public function test_both_bulk_bars_offer_apply_preset(): void
    {
        LazadaProductGroup::create(['name' => 'LZ offer', 'lazada_category_id' => 1]);
        TikTokProductGroup::create(['name' => 'TT offer', 'tiktok_category_id' => 'cat-1']);
        $this->seedProduct('OFFER-1');

        $lz = $this->actingAs($this->manager(['manage_lazada/product', 'view_lazada/product']))
            ->get(route('ext.lazada.products.index'))->assertOk();
        $lz->assertSee('Apply preset');
        $lz->assertSee(route('ext.lazada.products.bulk_apply_preset'));

        $tt = $this->actingAs($this->manager(['manage_tiktok/product', 'view_tiktok/product']))
            ->get(route('ext.tiktok.products.index'))->assertOk();
        $tt->assertSee('Apply preset');
        $tt->assertSee(route('ext.tiktok.products.bulk_apply_preset'));
    }
}
