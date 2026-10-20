<?php

namespace Tests\Feature;

use Extensions\TikTok\Models\TikTokListing;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTok\TikTokLinkedRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendReadsOnlyTheListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiktok_automations_cover_a_linked_product_outside_any_group(): void
    {
        $store = TikTokStore::create(['store_name' => 'Main store', 'mode' => 'production']);
        app()->instance('tiktok.route-store', $store);
        TikTokListing::query()->create(['store_id' => $store->id, 'product_id' => 11, 'tiktok_product_id' => 'tt-11']);
        TikTokListing::query()->create(['store_id' => $store->id, 'product_id' => 12]);
        $group = TikTokProductGroup::create(['store_id' => $store->id, 'name' => 'Legacy']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => 13, 'tiktok_product_id' => 'tt-13']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => 11, 'tiktok_product_id' => 'tt-old']);

        $rows = TikTokLinkedRows::get();

        $this->assertSame([11 => 'tt-11', 13 => 'tt-13'], $rows->mapWithKeys(fn ($r) => [(int) $r->product_id => (string) $r->tiktok_product_id])->sort()->all(),
            'a linked listing outside any group is covered, a group-only link still is, and the listing wins over its group row');
    }

    public function test_no_channel_reads_a_group_setting_at_send_time(): void
    {
        $files = array_merge(
            glob(base_path('extensions/{shopee,lazada,tiktok,woocommerce,ventacart}/{Services,Commands}/*.php'), GLOB_BRACE),
            glob(base_path('extensions/{shopee,lazada,tiktok,woocommerce,ventacart}/{Services,Commands}/*/*.php'), GLOB_BRACE),
            glob(base_path('extensions/{shopee,lazada,tiktok,woocommerce,ventacart}/Controllers/*ListingController.php'), GLOB_BRACE),
            glob(base_path('extensions/{shopee,lazada}/Controllers/*ProductController.php'), GLOB_BRACE),
            [base_path('app/Services/Media/ListingWatermark.php'), base_path('app/Channels/Listings/ListingSort.php'), base_path('app/Channels/Listings/ListingGroup.php')],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['InheritedSettings', 'groupTemplateId', 'groups()->first()', 'groups->first()->markup', 'ListingGroup::follow', 'joinGroupMarkup'] as $word) {
                $this->assertStringNotContainsString($word, $source, str_replace(base_path() . '/', '', $file) . " reads a group setting ({$word})");
            }
        }
    }
}
