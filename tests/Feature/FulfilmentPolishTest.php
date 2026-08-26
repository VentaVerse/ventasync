<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\CatalogVariations;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fulfilment polish of 2026-08-26: the variation names its axis
 * ("Color/Finish: Red"), and the marketplace cards lead with To Pack and
 * print To Handover beside it for the shelf count.
 */
class FulfilmentPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        // A connected, freshly synced shop, so the card carries no caveat.
        DB::table('shopee_settings')->insert([
            'partner_id' => 1, 'partner_key' => encrypt('k'), 'shop_id' => 2,
            'access_token' => encrypt('t'), 'refresh_token' => encrypt('r'), 'mode' => 'production',
            'expires_at' => now()->addHours(2), 'refresh_expires_at' => now()->addDays(25),
            'last_order_sync_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── The variation names its axis ──

    public function test_a_combination_sku_resolves_to_every_axis_it_sits_on(): void
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'GP200', 'sku' => 'GP200', 'quantity' => 1, 'price' => 100, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        $povIds = [];
        foreach (['Color/Finish' => 'Red', 'Size' => 'M'] as $axis => $value) {
            $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
            DB::table($pfx . 'option_description')->insert(['option_id' => $optionId, 'language_id' => $langId, 'name' => $axis]);
            $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId(['product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0]);
            $valueId = (int) DB::table($pfx . 'option_value')->insertGetId(['option_id' => $optionId, 'image' => '', 'sort_order' => 0]);
            DB::table($pfx . 'option_value_description')->insert(['option_value_id' => $valueId, 'language_id' => $langId, 'option_id' => $optionId, 'name' => $value]);
            $povIds[] = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $productId, 'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $axis === 'Size' ? 'GP200-SIZE-M' : '', 'quantity' => 1, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+',
                'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 0,
            ]);
        }
        $comboId = (int) DB::table('product_option_combinations')->insertGetId([
            'product_id' => $productId, 'sku' => 'GP200-RED-M', 'quantity' => 1, 'absolute_price' => 100, 'image' => null, 'status' => 1, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($povIds as $povId) {
            DB::table('product_option_combination_values')->insert(['combination_id' => $comboId, 'product_option_value_id' => $povId]);
        }

        $this->assertSame(
            [['name' => 'Color/Finish', 'value' => 'Red'], ['name' => 'Size', 'value' => 'M']],
            CatalogVariations::pairsFor('GP200-RED-M', 'Red,M'),
            'the catalog names both axes, in option order'
        );
        $this->assertSame([['name' => 'Size', 'value' => 'M']], CatalogVariations::pairsFor('GP200-SIZE-M'), 'a single-axis option value SKU');

        // A SKU the catalog does not know: what the marketplace sent, read as far as it goes.
        $this->assertSame([['name' => 'Color Family', 'value' => 'Red'], ['name' => 'Size', 'value' => 'M']], CatalogVariations::pairsFor('NOPE', 'Color Family:Red, Size:M'));
        $this->assertSame([['name' => '', 'value' => 'Red']], CatalogVariations::pairsFor('NOPE', 'Red'));
        $this->assertSame([['name' => '', 'value' => 'Graphite, US layout']], CatalogVariations::pairsFor('NOPE', 'Graphite, US layout'), 'a bare string is not split: nothing says where one value ends');
        $this->assertSame([], CatalogVariations::pairsFor('NOPE', ''));

        $html = Blade::render('<x-fulfilment.variation sku="GP200-RED-M" fallback="Red,M" />');
        $this->assertStringContainsString('<span class="co-var__name">Color/Finish</span><span class="co-var__value">Red</span>', $html);
        $this->assertStringContainsString('<span class="co-var__name">Size</span><span class="co-var__value">M</span>', $html);
        $this->assertSame('', trim(Blade::render('<x-fulfilment.variation sku="" fallback="" />')), 'nothing to say prints nothing');
    }

    // ── The cards lead with To Pack and print To Handover beside it ──

    public function test_the_shopee_card_counts_to_pack_and_to_handover_separately(): void
    {
        foreach (['READY_TO_SHIP', 'READY_TO_SHIP', 'PROCESSED', 'COMPLETED'] as $i => $status) {
            ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'STAGE00' . $i, 'status' => $status, 'raw' => ['order_status' => $status]]);
        }

        $group = UserGroup::create(['name' => 'Polish operators']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        // The lead is To Pack (2), not the whole waiting count (3).
        $this->assertMatchesRegularExpression('/fh-card__lead x-num[^>]*>2</', $html);
        $this->assertStringContainsString('<span class="fh-card__label">to pack</span>', $html);
        $this->assertMatchesRegularExpression('/fh-card__stage-count x-num[^>]*>1<\/span> to handover/', $html);
        // The head sentence still counts everything the seller holds.
        $this->assertStringContainsString('3 orders waiting', $html);
    }

    public function test_a_card_with_nothing_to_pack_says_so(): void
    {
        ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'STAGE-HANDOVER', 'status' => 'PROCESSED', 'raw' => ['order_status' => 'PROCESSED']]);
        $group = UserGroup::create(['name' => 'Polish operators 2']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="fh-card__label">nothing to pack</span>', $html);
        $this->assertMatchesRegularExpression('/fh-card__stage-count x-num[^>]*>1<\/span> to handover/', $html);
    }
}
