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

class FulfilmentPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        DB::table('shopee_settings')->insert([
            'partner_id' => 1, 'partner_key' => encrypt('k'), 'shop_id' => 2,
            'access_token' => encrypt('t'), 'refresh_token' => encrypt('r'), 'mode' => 'production',
            'expires_at' => now()->addHours(2), 'refresh_expires_at' => now()->addDays(25),
            'last_order_sync_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

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

        $this->assertSame([['name' => 'Color Family', 'value' => 'Red'], ['name' => 'Size', 'value' => 'M']], CatalogVariations::pairsFor('NOPE', 'Color Family:Red, Size:M'));
        $this->assertSame([['name' => '', 'value' => 'Red']], CatalogVariations::pairsFor('NOPE', 'Red'));
        $this->assertSame([['name' => '', 'value' => 'Graphite, US layout']], CatalogVariations::pairsFor('NOPE', 'Graphite, US layout'), 'a bare string is not split: nothing says where one value ends');
        $this->assertSame([], CatalogVariations::pairsFor('NOPE', ''));

        $html = Blade::render('<x-fulfilment.variation sku="GP200-RED-M" fallback="Red,M" />');
        $this->assertStringContainsString('<span class="co-var__name">Color/Finish</span><span class="co-var__value">Red</span>', $html);
        $this->assertStringContainsString('<span class="co-var__name">Size</span><span class="co-var__value">M</span>', $html);
        $this->assertSame('', trim(Blade::render('<x-fulfilment.variation sku="" fallback="" />')), 'nothing to say prints nothing');
    }

    public function test_top_products_rank_by_quantity_then_revenue_then_name_and_show_ten_with_the_tail(): void
    {
        $order = ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'TOP-TODAY', 'status' => 'READY_TO_SHIP', 'order_created_at' => now(), 'raw' => []]);
        $rows = [
            ['sku' => 'TIE-CHEAP', 'name' => 'Tie cheap', 'quantity' => 3, 'price' => 100],
            ['sku' => 'TIE-DEAR', 'name' => 'Tie dear', 'quantity' => 3, 'price' => 900, 'variation' => 'Red'],
            ['sku' => 'SAME-B', 'name' => 'Same b', 'quantity' => 2, 'price' => 50],
            ['sku' => 'SAME-A', 'name' => 'Same a', 'quantity' => 2, 'price' => 50],
        ];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = ['sku' => 'ONE-' . $i, 'name' => 'One ' . $i, 'quantity' => 1, 'price' => 10];
        }
        foreach ($rows as $r) {
            DB::table('shopee_order_products')->insert($r + ['shopee_order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $group = UserGroup::create(['name' => 'Polish operators 3']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        preg_match_all('/<span class="fh-top__sku">([^<]+)<\/span>/', $html, $m);
        $this->assertCount(10, $m[1], 'ten rows, never a blank slot');
        $this->assertSame(['TIE-DEAR', 'TIE-CHEAP', 'SAME-A', 'SAME-B'], array_slice($m[1], 0, 4));
        $this->assertStringContainsString('more products sold today', $html);
        $this->assertMatchesRegularExpression('/fh-top__meta">\s*<span class="co-var">\s*<span class="co-var__value">Red<\/span>/', $html);
        $this->assertMatchesRegularExpression('/and <span class="x-num">2<\/span> more products sold today/', $html);

        $again = $this->actingAs($user)->get('/channels/fulfilment')->getContent();
        preg_match_all('/<span class="fh-top__sku">([^<]+)<\/span>/', $again, $m2);
        $this->assertSame($m[1], $m2[1]);
    }

    public function test_fewer_than_ten_products_list_only_what_sold(): void
    {
        $order = ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'TOP-THREE', 'status' => 'READY_TO_SHIP', 'order_created_at' => now(), 'raw' => []]);
        foreach (['A', 'B', 'C'] as $k) {
            DB::table('shopee_order_products')->insert(['shopee_order_id' => $order->id, 'sku' => 'FEW-' . $k, 'name' => 'Few ' . $k, 'quantity' => 1, 'price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        $group = UserGroup::create(['name' => 'Polish operators 4']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<li class="fh-top__row">'), 'three sold, three rows, no empty ranks');
        $this->assertStringNotContainsString('more products sold today', $html);
    }

    public function test_shopee_offers_the_ship_by_sort_as_an_option_and_keeps_its_default(): void
    {
        foreach ([['SB-LATE', 3, 200], ['SB-SOON', 1, 100]] as [$sn, $daysLeft, $qty]) {
            ShopeeOrder::create(['region' => 'ph', 'order_sn' => $sn, 'status' => 'READY_TO_SHIP',
                'order_created_at' => now()->subHours($qty), 'raw' => ['ship_by_date' => now()->addDays($daysLeft)->timestamp]]);
        }
        $group = UserGroup::create(['name' => 'Polish operators 5']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment?channel=shopee:' . \Extensions\shopee\Models\ShopeeSetting::query()->value('id') . '&tab=PENDING')->assertOk()->getContent();
        $this->assertStringContainsString('Ship-by time, soonest first', $html);
        $this->assertLessThan(strpos($html, 'SB-SOON'), strpos($html, 'SB-LATE'));

        $sorted = $this->actingAs($user)->get('/channels/fulfilment?channel=shopee:' . \Extensions\shopee\Models\ShopeeSetting::query()->value('id') . '&tab=PENDING&sort=ship_by_asc')->assertOk()->getContent();
        $this->assertLessThan(strpos($sorted, 'SB-LATE'), strpos($sorted, 'SB-SOON'), 'soonest deadline first when asked');
    }

    public function test_the_shopee_card_counts_to_pack_and_to_handover_separately(): void
    {
        foreach (['READY_TO_SHIP', 'READY_TO_SHIP', 'PROCESSED', 'COMPLETED'] as $i => $status) {
            ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'STAGE00' . $i, 'status' => $status, 'raw' => ['order_status' => $status]]);
        }

        $group = UserGroup::create(['name' => 'Polish operators']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/fh-card__fig-l">to pack<\/span>\s*<span class="fh-card__fig-n x-num[^>]*>2</', $html);
        $this->assertMatchesRegularExpression('/fh-card__fig-l">to handover<\/span>\s*<span class="fh-card__fig-n x-num[^>]*>1</', $html);
        $this->assertStringNotContainsString('fh-card__lead x-num', $html, 'the one-number lead is gone from a channel with stages');
        $this->assertStringContainsString('3 orders waiting', $html);
    }

    public function test_the_sidebar_badge_counts_to_pack_only(): void
    {
        foreach (['READY_TO_SHIP', 'READY_TO_SHIP', 'PROCESSED', 'PROCESSED', 'PROCESSED'] as $i => $status) {
            ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'BADGE0' . $i, 'status' => $status, 'raw' => ['order_status' => $status]]);
        }
        $group = UserGroup::create(['name' => 'Badge readers']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        \App\Support\FulfilmentBadge::forget($user);
        $this->assertSame(2, \App\Support\FulfilmentBadge::pending($user), 'two to pack; the three awaiting collection are not counted');
    }

    public function test_a_card_with_nothing_to_pack_says_so(): void
    {
        ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'STAGE-HANDOVER', 'status' => 'PROCESSED', 'raw' => ['order_status' => 'PROCESSED']]);
        $group = UserGroup::create(['name' => 'Polish operators 2']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/order'])->pluck('id')->all());
        $user = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($user)->get('/channels/fulfilment')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/fh-card__fig-l">nothing to pack<\/span>\s*<span class="fh-card__fig-n x-num is-clear[^>]*>0</', $html);
        $this->assertMatchesRegularExpression('/fh-card__fig-l">to handover<\/span>\s*<span class="fh-card__fig-n x-num[^>]*>1</', $html);
    }
}
