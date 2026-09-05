<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeListingReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Ready to push" as a computed state (#101): can the one-click push
 * succeed, answered from the ERP's own records before anything is
 * pressed. One shared computation feeds the listing page, the listings
 * index rows, the readiness filter and the bulk push's refusal - so
 * Ready means the same thing everywhere, and a bulk push refuses to
 * start rather than half-failing across twelve products.
 */
class ShopeeReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    private array $sentPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->recordPayload($path, $body);

                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item_id' => 990077]]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        // A category whose sheet demands one answer.
        ShopeeCategoryTemplate::query()->create([
            'category_id' => 106755, 'region' => 'ph',
            'attributes' => [[
                'attribute_id' => 2000, 'original_attribute_name' => 'Warranty Type',
                'is_mandatory' => true, 'input_type' => 'TEXT_FILED', 'attribute_value_list' => [],
            ]],
            'fetched_at' => now(),
        ]);
    }

    public function recordPayload(string $path, array $body): void
    {
        $this->sentPayloads[] = ['path' => $path, 'body' => $body];
    }

    private int $managerSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Readiness checkers ' . (++$this->managerSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, string $image = 'catalog/x.png'): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => $image, 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    /** The index scopes to grouped-or-linked products; a catch-all group
     *  (like every live one) brings the seeded products into view. */
    private function catchAllGroup(): void
    {
        \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Readiness scope',
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);
    }

    private function readyListing(int $productId): void
    {
        ShopeeListing::query()->create([
            'product_id' => $productId,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
            'attribute_values' => ['2000' => '1 year'],
        ]);
    }

    /**
     * The store has been set up (the owner, 2026-09-05): its categories and
     * couriers are on file, so a gap is the product's own choice, not
     * "none have been fetched for this store yet".
     */
    private function storeSetUp(): void
    {
        \Extensions\shopee\Models\ShopeeCategory::create(['category_id' => 106755, 'parent_id' => null, 'name' => 'Pedals', 'level' => 0, 'leaf' => true]);
        \Extensions\shopee\Models\ShopeeLogistic::create(['logistics_channel_id' => 41003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true]);
    }

    /**
     * A group is a preset the product inherits (the owner, 2026-09-05): a
     * listing with no category or couriers of its own is ready when its
     * group carries them, because every push now fills the blanks from
     * the group.
     */
    public function test_a_grouped_product_inherits_the_groups_category_and_couriers(): void
    {
        $this->storeSetUp();
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Pedals preset', 'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);
        \Extensions\shopee\Models\ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 106755], ['attributes' => ['attribute_list' => []]]);
        $grouped = $this->seedProduct('Grouped bare', 'GRP-1');
        // No category, no couriers of its own; the sheet's one answer given.
        ShopeeListing::query()->create(['product_id' => $grouped, 'attribute_values' => ['2000' => '1 year']]);
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $grouped]);
        $alone = $this->seedProduct('Alone bare', 'ALN-1');
        ShopeeListing::query()->create(['product_id' => $alone]);

        $out = app(ShopeeListingReadiness::class)->forProducts([$grouped, $alone]);

        $this->assertTrue($out[$grouped]['ready'], implode(' | ', $out[$grouped]['missing']));
        $this->assertFalse($out[$alone]['ready']);
        $this->assertContains('a Shopee category', $out[$alone]['missing']);

        // The listing's own choice still wins over the group's.
        ShopeeListing::query()->where('product_id', $grouped)->update(['shopee_category_id' => 999999]);
        $out = app(ShopeeListingReadiness::class)->forProducts([$grouped]);
        $this->assertStringContainsString('attribute sheet', implode(' ', $out[$grouped]['missing']), 'its own unread category, not the group\'s, is what readiness judged');
    }

    public function test_the_computation_names_each_missing_thing(): void
    {
        $this->storeSetUp();
        $ready = $this->seedProduct('Ready one', 'RDY-1');
        $this->readyListing($ready);

        $bare = $this->seedProduct('Bare one', 'RDY-2', '');

        $unanswered = $this->seedProduct('Unanswered one', 'RDY-3');
        ShopeeListing::query()->create([
            'product_id' => $unanswered,
            'shopee_category_id' => 106755, 'logistic_ids' => [41003],
        ]);

        $unreadSheet = $this->seedProduct('Unread sheet one', 'RDY-4');
        ShopeeListing::query()->create([
            'product_id' => $unreadSheet,
            'shopee_category_id' => 999999, 'logistic_ids' => [41003],
        ]);

        $out = app(ShopeeListingReadiness::class)
            ->forProducts([$ready, $bare, $unanswered, $unreadSheet]);

        $this->assertTrue($out[$ready]['ready']);
        $this->assertSame([], $out[$ready]['missing']);

        $this->assertFalse($out[$bare]['ready']);
        $this->assertContains('a Shopee category', $out[$bare]['missing']);
        $this->assertContains('at least one courier', $out[$bare]['missing']);
        $this->assertContains('a product image on the catalog product', $out[$bare]['missing']);

        $this->assertFalse($out[$unanswered]['ready']);
        $this->assertContains('answers for 1 required attribute (Warranty Type)', $out[$unanswered]['missing']);

        $this->assertFalse($out[$unreadSheet]['ready'],
            'A category whose sheet was never read cannot be called Ready.');
        $this->assertStringContainsString("attribute sheet", implode(' ', $out[$unreadSheet]['missing']));
    }

    public function test_the_index_says_ready_or_names_what_is_missing(): void
    {
        $this->catchAllGroup();
        $ready = $this->seedProduct('Ready index product', 'RDY-IDX-1');
        $this->readyListing($ready);
        $bare = $this->seedProduct('Bare index product', 'RDY-IDX-2', '');
        // On the store with a blank record (#201): what Add products leaves behind.
        ShopeeListing::query()->create(['product_id' => $bare]);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.products.index'));

        $r->assertOk();
        $r->assertSee('Ready to push');
        $r->assertSee('Missing:');
        $r->assertSee('a Shopee category');
    }

    public function test_the_ready_filter_answers_the_same_computation(): void
    {
        $this->catchAllGroup();
        $ready = $this->seedProduct('Ready filter product', 'RDY-F-1');
        $this->readyListing($ready);
        $bare = $this->seedProduct('Bare filter product', 'RDY-F-2', '');
        ShopeeListing::query()->create(['product_id' => $bare]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index', ['sync_status' => 'ready']));

        $r->assertOk();
        $r->assertSee('Ready filter product');
        $r->assertDontSee('Bare filter product');

        $r2 = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.index', ['sync_status' => 'not_ready']));
        $r2->assertSee('Bare filter product');
        $r2->assertDontSee('Ready filter product');
    }

    public function test_a_bulk_push_refuses_to_start_when_any_selection_is_not_ready(): void
    {
        $this->storeSetUp();
        $ready = $this->seedProduct('Ready bulk product', 'RDY-B-1');
        $this->readyListing($ready);
        $bare = $this->seedProduct('Bare bulk product', 'RDY-B-2', '');

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.products.bulk_push'), [
            'product_ids' => [$ready, $bare],
        ]);

        $this->assertNull(collect($this->sentPayloads)->firstWhere('path', '/api/v2/product/add_item'),
            'Nothing may be pushed when part of the selection is not ready.');
        $r->assertSessionHas('error');
        $error = session('error');
        $this->assertStringContainsString('Nothing was pushed', $error);
        $this->assertStringContainsString("#{$bare} needs", $error);
    }
}
