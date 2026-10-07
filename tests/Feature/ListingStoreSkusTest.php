<?php

namespace Tests\Feature;

use App\Integrations\Listings\ListingVariations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingStoreSkusTest extends TestCase
{
    use RefreshDatabase;

    private function product(): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => 'EXL110', 'sku' => 'EXL110', 'quantity' => 9, 'price' => 420, 'status' => 1, 'image' => '',
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        foreach ([['EXL110-1046', 1], ['EXL110-0942', 2], ['EXL110-1149', 3]] as [$sku, $sort]) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 420, 'status' => 1, 'sort_order' => $sort,
            ]);
        }

        return $pid;
    }

    public function test_a_variation_the_store_sells_but_its_item_lacks_is_named(): void
    {
        $pid = $this->product();
        ListingVariations::remember('shopee', 5, $pid, ['EXL110-1046', 'exl110-0942']);

        $missing = ListingVariations::missing('shopee', 5, [$pid]);
        $this->assertSame(['EXL110-1149'], array_column($missing[$pid], 'sku'), 'SKUs match whatever their case');
        $this->assertSame('Not on Shopee: EXL110-1149.', ListingVariations::missingMessage('Shopee', $missing[$pid]));
    }

    public function test_switching_it_off_for_the_store_or_in_the_catalog_takes_it_off(): void
    {
        $pid = $this->product();
        ListingVariations::remember('shopee', 5, $pid, ['EXL110-1046', 'EXL110-0942']);

        DB::table(ListingVariations::TABLE)->insert(['channel' => 'shopee', 'store_id' => 5, 'product_id' => $pid, 'sku' => 'exl110-1149']);
        $this->assertSame([], ListingVariations::missing('shopee', 5, [$pid]), 'switched off for this store');

        DB::table(ListingVariations::TABLE)->delete();
        DB::table('product_option_combinations')->where('sku', 'EXL110-1149')->update(['status' => 0]);
        $this->assertSame([], ListingVariations::missing('shopee', 5, [$pid]), 'switched off in the catalog');
    }

    public function test_a_store_never_read_is_not_a_gap_and_another_store_is_its_own(): void
    {
        $pid = $this->product();
        $this->assertSame([], ListingVariations::missing('shopee', 5, [$pid]));

        ListingVariations::remember('shopee', 5, $pid, ['EXL110-1046', 'EXL110-0942', 'EXL110-1149']);
        $this->assertSame([], ListingVariations::missing('shopee', 5, [$pid]), 'the item holds all three');
        $this->assertSame([], ListingVariations::missing('shopee', 6, [$pid]), 'store 6 was never read');
        $this->assertSame([], ListingVariations::missing('lazada', 5, [$pid]), 'another channel is its own');
    }

    public function test_a_fresh_read_replaces_the_last_and_a_catalog_delete_forgets_it(): void
    {
        $pid = $this->product();
        ListingVariations::remember('shopee', 5, $pid, ['EXL110-1046']);
        ListingVariations::remember('shopee', 5, $pid, ['EXL110-1046', 'EXL110-0942', 'EXL110-1149']);
        $this->assertSame([], ListingVariations::missing('shopee', 5, [$pid]));
        $this->assertSame(1, DB::table(ListingVariations::STORE_SKUS)->count());

        ListingVariations::forgetProducts([$pid]);
        $this->assertSame(0, DB::table(ListingVariations::STORE_SKUS)->count());
    }

    public function test_the_error_and_the_missing_line_are_both_kept(): void
    {
        $this->assertSame('Push failed: x. Not on Shopee: A.', ListingVariations::withMissing('Push failed: x.', 'Not on Shopee: A.'));
        $this->assertSame('Not on Shopee: A.', ListingVariations::withMissing(null, 'Not on Shopee: A.'));
        $this->assertNull(ListingVariations::withMissing(null, null));
    }
}
