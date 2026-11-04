<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingVariation;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogVariationChangesReachChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');
        Http::fake();
    }

    private function editor(): User
    {
        $group = UserGroup::create(['name' => 'Catalog editors']);
        $group->permissions()->attach(Permission::query()->where('key', 'like', '%catalog%')->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(): int
    {
        $pid = DB::table('products')->insertGetId([
            'sku' => 'AMP-1', 'quantity' => 0, 'price' => 1000, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Practice amp', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function save(User $user, int $pid, array $values): void
    {
        $this->actingAs($user)->post(route('products.variations.update', $pid), [
            'variation_type_name' => $values === [] ? '' : 'Size',
            'values' => $values,
        ])->assertRedirect();
    }

    private function valueIds(int $pid): array
    {
        return DB::table('product_variation_values')->where('product_id', $pid)->pluck('variation_value_id', 'sku')->map(fn ($v) => (int) $v)->all();
    }

    private function povId(int $pid, string $sku): int
    {
        return (int) DB::table('product_variation_values')->where('product_id', $pid)->where('sku', $sku)->value('product_variation_value_id');
    }

    public function test_a_renamed_or_removed_variation_reaches_each_channel_through_its_own_listener(): void
    {
        $user = $this->editor();
        $pid = $this->product();
        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-S', 'quantity' => 2, 'absolute_price' => 1000],
            ['name' => 'Medium', 'sku' => 'AMP-M', 'quantity' => 3, 'absolute_price' => 1000],
        ]);
        $ids = $this->valueIds($pid);

        $shopee = ShopeeStore::query()->create(['partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2, 'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production']);
        ShopeeListingLink::query()->withoutGlobalScopes()->create(['store_id' => $shopee->id, 'product_id' => $pid, 'shopee_item_id' => '900', 'shopee_model_id' => '901', 'sku' => 'AMP-S']);
        $lazada = LazadaStore::query()->create(['store_name' => 'Main', 'region' => 'PH', 'app_key' => 'k', 'app_secret' => 's']);
        $listing = LazadaListing::query()->withoutGlobalScopes()->create(['store_id' => $lazada->id, 'product_id' => $pid]);
        $small = LazadaListingVariation::query()->create(['lazada_listing_id' => $listing->id, 'product_variation_value_id' => $this->povId($pid, 'AMP-S'), 'seller_sku' => 'AMP-S']);
        $medium = LazadaListingVariation::query()->create(['lazada_listing_id' => $listing->id, 'product_variation_value_id' => $this->povId($pid, 'AMP-M'), 'seller_sku' => 'AMP-M']);

        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-SMALL', 'quantity' => 2, 'absolute_price' => 1000, 'variation_value_id' => $ids['AMP-S']],
        ]);

        $this->assertSame('AMP-SMALL', ShopeeListingLink::query()->withoutGlobalScopes()->value('sku'));
        $this->assertSame('AMP-SMALL', $small->fresh()->seller_sku);
        $this->assertNull($medium->fresh()->product_variation_value_id, 'the removed Medium is unlinked, not deleted');

        $this->save($user, $pid, []);
        $this->assertNull($small->fresh()->product_variation_value_id, 'removing every variation unlinks the rest');
    }

    public function test_a_switched_off_channel_still_follows_a_renamed_sku(): void
    {
        $user = $this->editor();
        $pid = $this->product();
        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-S', 'quantity' => 2, 'absolute_price' => 1000],
        ]);
        $ids = $this->valueIds($pid);

        $shopee = ShopeeStore::query()->create(['partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2, 'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production']);
        ShopeeListingLink::query()->withoutGlobalScopes()->create(['store_id' => $shopee->id, 'product_id' => $pid, 'shopee_item_id' => '900', 'shopee_model_id' => '901', 'sku' => 'AMP-S']);
        $lazada = LazadaStore::query()->create(['store_name' => 'Main', 'region' => 'PH', 'app_key' => 'k', 'app_secret' => 's']);
        $listing = LazadaListing::query()->withoutGlobalScopes()->create(['store_id' => $lazada->id, 'product_id' => $pid]);
        $small = LazadaListingVariation::query()->create(['lazada_listing_id' => $listing->id, 'product_variation_value_id' => $this->povId($pid, 'AMP-S'), 'seller_sku' => 'AMP-S']);

        $manager = $this->app->make(ExtensionManager::class);
        $manager->disable('shopee');
        $manager->disable('lazada');
        $this->app->instance(\App\Channels\ChannelRegistry::class, new \App\Channels\ChannelRegistry);

        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-SMALL', 'quantity' => 2, 'absolute_price' => 1000, 'variation_value_id' => $ids['AMP-S']],
        ]);

        $this->assertSame('AMP-SMALL', ShopeeListingLink::query()->withoutGlobalScopes()->value('sku'));
        $this->assertSame('AMP-SMALL', $small->fresh()->seller_sku);
    }

    public function test_with_the_channels_uninstalled_the_catalog_never_touches_their_tables(): void
    {
        $user = $this->editor();
        $pid = $this->product();
        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-S', 'quantity' => 2, 'absolute_price' => 1000],
            ['name' => 'Medium', 'sku' => 'AMP-M', 'quantity' => 3, 'absolute_price' => 1000],
        ]);
        $ids = $this->valueIds($pid);

        $manager = $this->app->make(ExtensionManager::class);
        $manager->uninstall('shopee');
        $manager->uninstall('lazada');
        $this->app->instance(\App\Channels\ChannelRegistry::class, new \App\Channels\ChannelRegistry);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->save($user, $pid, [
            ['name' => 'Small', 'sku' => 'AMP-SMALL', 'quantity' => 2, 'absolute_price' => 1000, 'variation_value_id' => $ids['AMP-S']],
        ]);
        $this->save($user, $pid, []);

        $touched = array_values(array_filter($queries, fn ($sql) => preg_match('/`?ch_(shopee|lazada)_/', $sql)));
        $this->assertSame([], $touched, 'saving the Master Catalog must not read or write an uninstalled channel');
    }
}
