<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Listings\ListingState;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeListingStates;
use Extensions\shopee\Services\Shopee\ShopeeLiveListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B of the channels rebuild (#184): ONE computation answers "where
 * does this product stand on Shopee?", and every surface renders it.
 * Before this, eleven status vocabularies and three disagreeing label maps
 * meant pages could not agree; a user can only trust a system whose
 * screens agree with each other.
 */
class ShopeeListingStateTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function product(string $sku, string $image = 'catalog/x.png', ?string $modified = null): int
    {
        $pfx = (string) config('catalog.prefix');

        return (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => $image, 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => $modified ?? now()->subDays(30),
            'date_available' => now()->subDays(30),
        ]);
    }

    private function readyListing(int $productId, array $extra = []): ShopeeListing
    {
        ShopeeCategoryTemplate::query()->firstOrCreate(
            ['category_id' => 100139, 'region' => 'production'],
            ['attributes' => ['attribute_list' => []], 'fetched_at' => now()]
        );

        return ShopeeListing::query()->create(array_merge([
            'product_id' => $productId,
            'shopee_category_id' => 100139,
            'logistic_ids' => [8003],
        ], $extra));
    }

    private function states(array $ids): array
    {
        return app(ShopeeListingStates::class)->forProducts($ids);
    }

    public function test_the_truth_table_from_not_listed_to_attention(): void
    {
        // Not listed, nothing configured: not ready, with CODED gaps.
        $bare = $this->product('BARE', '');
        $s = $this->states([$bare])[$bare];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertFalse($s->ready);
        $codes = array_column($s->missing, 'code');
        $this->assertContains(ListingState::GAP_CATEGORY, $codes);
        $this->assertContains(ListingState::GAP_COURIERS, $codes);
        $this->assertContains(ListingState::GAP_IMAGE, $codes);
        $this->assertStringContainsString('a Shopee category', $s->missingSummary());

        // Not listed with settings, sheet and image: ready.
        $ready = $this->product('READY');
        $this->readyListing($ready);
        $s = $this->states([$ready])[$ready];
        $this->assertSame(ListingState::NOT_LISTED, $s->state);
        $this->assertTrue($s->ready, $s->missingSummary());

        // Linked, mirror never read: unknown, never a guess.
        $unknown = $this->product('UNK');
        ShopeeProductLink::create(['product_id' => $unknown, 'shopee_item_id' => 111, 'sku' => 'UNK']);
        $s = $this->states([$unknown])[$unknown];
        $this->assertSame(ListingState::UNKNOWN, $s->state);
        $this->assertSame('Not checked yet', $s->label());

        // The observed statuses, one state each.
        foreach ([
            'NORMAL' => [ListingState::LIVE, 'Live', 'success'],
            'UNLIST' => [ListingState::INACTIVE, 'Unlisted', 'neutral'],
            'REVIEWING' => [ListingState::REVIEWING, 'Under review', 'warning'],
            'BANNED' => [ListingState::ATTENTION, 'Violation', 'danger'],
            'MISSING' => [ListingState::ATTENTION, 'Not found on Shopee', 'danger'],
        ] as $raw => [$state, $label, $tone]) {
            $pid = $this->product('S' . $raw);
            ShopeeProductLink::create([
                'product_id' => $pid, 'shopee_item_id' => $pid, 'sku' => 'S' . $raw,
                'live_status' => $raw, 'live_checked_at' => now(),
            ]);
            $s = $this->states([$pid])[$pid];
            $this->assertSame($state, $s->state, $raw);
            $this->assertSame($label, $s->label(), $raw);
            $this->assertSame($tone, $s->tone(), $raw);
        }
    }

    public function test_live_and_out_of_date_are_different_facts_and_both_are_told(): void
    {
        // Pushed, then the catalogue moved: live, not in sync.
        $moved = $this->product('MOVED', 'catalog/x.png', now()->format('Y-m-d H:i:s'));
        $this->readyListing($moved, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $moved, 'shopee_item_id' => 900, 'sku' => 'MOVED',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $s = $this->states([$moved])[$moved];
        $this->assertSame(ListingState::LIVE, $s->state);
        $this->assertFalse($s->inSync);
        $this->assertSame('Catalogue changed', $s->driftLabel());
        $this->assertStringContainsString('changed after the last push', $s->reasons[0]);

        // Pushed after the last catalogue change: in sync, no chip.
        $steady = $this->product('STEADY', 'catalog/x.png', now()->subDays(3)->format('Y-m-d H:i:s'));
        $this->readyListing($steady, ['last_pushed_at' => now()->subDay()]);
        ShopeeProductLink::create(['product_id' => $steady, 'shopee_item_id' => 901, 'sku' => 'STEADY',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $s = $this->states([$steady])[$steady];
        $this->assertTrue($s->inSync);
        $this->assertNull($s->driftLabel());

        // Linked but never pushed from here (matched in): nothing to
        // compare, and the engine says so instead of guessing.
        $adopted = $this->product('ADOPTED');
        ShopeeProductLink::create(['product_id' => $adopted, 'shopee_item_id' => 902, 'sku' => 'ADOPTED',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        $this->assertNull($this->states([$adopted])[$adopted]->inSync);
    }

    public function test_a_product_with_many_rows_gets_one_state_and_the_worst_wins(): void
    {
        $pid = $this->product('MULTI');
        foreach ([['shopee_model_id' => 1, 'live_status' => 'NORMAL'], ['shopee_model_id' => 2, 'live_status' => 'BANNED']] as $row) {
            ShopeeProductLink::create(array_merge([
                'product_id' => $pid, 'shopee_item_id' => 950, 'sku' => 'MULTI', 'live_checked_at' => now(),
            ], $row));
        }

        $s = $this->states([$pid])[$pid];
        $this->assertSame(ListingState::ATTENTION, $s->state, 'one banned variation IS the product\'s situation');
        $this->assertSame('Violation', $s->label());
    }

    public function test_a_push_writes_the_mirror_through_with_shopees_own_answer(): void
    {
        // The write-through asks Shopee, never assumes: a fresh item can be
        // NORMAL or REVIEWING and only Shopee knows which.
        $fake = new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['response' => ['item_list' => [
                    ['item_id' => 990077, 'item_status' => 'REVIEWING'],
                ]]]];
            }
        };

        $pid = $this->product('PUSHED');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 990077, 'sku' => 'PUSHED']);

        (new ShopeeLiveListing($fake))->confirm(['mode' => 'production', 'partner_id' => 1, 'partner_key' => 'k', 'access_token' => 't', 'shop_id' => 2], [990077]);

        $link = ShopeeProductLink::query()->where('shopee_item_id', 990077)->first();
        $this->assertSame('REVIEWING', $link->live_status);
        $this->assertNotNull($link->live_checked_at);
        $this->assertSame(ListingState::REVIEWING, $this->states([$pid])[$pid]->state);
    }

    public function test_the_write_through_swallows_a_failed_read_because_the_push_already_succeeded(): void
    {
        $fake = new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                throw new \RuntimeException('marketplace down');
            }
        };

        $pid = $this->product('BLIND');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 990088, 'sku' => 'BLIND']);

        (new ShopeeLiveListing($fake))->confirm(['mode' => 'production', 'partner_id' => 1, 'partner_key' => 'k', 'access_token' => 't', 'shop_id' => 2], [990088]);

        $this->assertNull(ShopeeProductLink::query()->where('shopee_item_id', 990088)->value('live_status'), 'stays honestly blank');
    }

    public function test_shopee_answers_the_core_contract_every_channel_will_share(): void
    {
        $pid = $this->product('VIACORE');
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 970, 'sku' => 'VIACORE',
            'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $sources = app(IntegrationRegistry::class)->listingStateSources();
        $this->assertNotEmpty($sources, 'the Shopee provider registers as a ListingStateSource');

        $states = collect($sources)->map(fn ($s) => $s->listingStates([$pid]))->first();
        $this->assertSame(ListingState::LIVE, $states[$pid]->state);
    }
}
