<?php

namespace Tests\Feature;

use App\Integrations\OneGroupRule;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OneGroupRuleTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name = 'Main'): ShopeeSetting
    {
        return ShopeeSetting::query()->create([
            'store_name' => $name, 'enabled' => true, 'mode' => 'live',
            'partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2,
            'access_token' => 't', 'refresh_token' => 'r', 'region' => 'ph',
        ]);
    }

    private function group(ShopeeSetting $store, string $name): ShopeeProductGroup
    {
        return ShopeeProductGroup::create([
            'shopee_setting_id' => $store->id, 'name' => $name,
            'shopee_category_id' => 100, 'logistic_ids' => [1],
        ]);
    }

    public function test_free_held_and_moved_are_the_three_verdicts(): void
    {
        $store = $this->store();
        $a = $this->group($store, 'Group A');
        $b = $this->group($store, 'Group B');

        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $a->id, 'product_id' => 11]);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $a->id, 'product_id' => 12]);

        $claim = OneGroupRule::claim(
            'shopee_product_group_products', 'shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id',
            $b->id, [11, 12, 13], [12]
        );

        $this->assertSame([13], $claim['free'], 'a product nobody owns is free');
        $this->assertSame([11 => 'Group A'], $claim['held'], 'an owned product is held and its owner named');
        $this->assertSame([12 => 'Group A'], $claim['moved'], 'move_ids is the explicit transfer');
        $this->assertSame(0, ShopeeProductGroupProduct::where('product_id', 12)->where('shopee_product_group_id', $a->id)->count(),
            'a move deletes the old membership');
        $this->assertSame(1, ShopeeProductGroupProduct::where('product_id', 11)->count(),
            'a held product keeps its old membership untouched');
    }

    public function test_another_stores_group_never_blocks(): void
    {
        $one = $this->store('One');
        $two = $this->store('Two');
        $a = $this->group($one, 'Store one group');
        $b = $this->group($two, 'Store two group');

        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $a->id, 'product_id' => 21]);

        $claim = OneGroupRule::claim(
            'shopee_product_group_products', 'shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id',
            $b->id, [21]
        );

        $this->assertSame([21], $claim['free'],
            'group scoping is per store: a membership in another shop is not this shop\'s business');
    }

    public function test_the_search_names_the_owner(): void
    {
        $store = $this->store();
        $a = $this->group($store, 'Owner group');
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $a->id, 'product_id' => 31]);

        $owners = OneGroupRule::owners(
            'shopee_product_group_products', 'shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id',
            0, [31, 32]
        );

        $this->assertSame(['group_id' => $a->id, 'name' => 'Owner group'], $owners[31]);
        $this->assertArrayNotHasKey(32, $owners);
    }
}
