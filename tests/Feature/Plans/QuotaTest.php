<?php

namespace Tests\Feature\Plans;

use App\Actions\Catalog\ProductWrites;
use App\Models\ApiClient;
use App\Models\Catalog\Order;
use App\Models\User;
use App\Plans\Counters;
use App\Plans\CountsAsStore;
use App\Plans\PlanLimitReached;
use App\Plans\Quota;
use App\Services\Catalog\ProductCreator;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Extensions\shopee\Services\Shopee\ShopeeItemImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuotaTest extends TestCase
{
    use RefreshDatabase;
    use SetsServerPlan;

    private function product(string $sku): int
    {
        return (int) DB::table(config('catalog.prefix') . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 1, 'price' => 100, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
    }

    private function filesMatching(string $pattern): array
    {
        $found = [];
        foreach ([app_path(), base_path('extensions')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() === 'php' && preg_match($pattern, $file->getContents())) {
                    $found[$file->getPathname()] = $file->getContents();
                }
            }
        }

        return $found;
    }

    public function test_self_hosted_has_no_limits(): void
    {
        $this->selfHosted();
        $this->product('A');

        foreach (['products', 'users', 'stores', 'api_apps', 'orders_month'] as $limit) {
            $this->assertNull(Quota::refusal($limit), $limit);
        }
    }

    public function test_a_limit_refuses_once_reached_in_plain_words(): void
    {
        $this->onServer([], ['products' => 2]);
        $this->product('A');
        $this->assertNull(Quota::refusal('products'));

        $this->product('B');
        $this->assertSame('Your plan allows 2 products.', Quota::refusal('products'));
    }

    public function test_the_product_form_refuses_over_the_limit(): void
    {
        $admin = $this->admin();
        $this->onServer([], ['products' => 1]);
        $this->product('A');

        $this->actingAs($admin)->get(route('products.index'))->assertSee('of 1 on your plan');
        $this->actingAs($admin)->get(route('products.create'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error', 'Your plan allows 1 product.');

        $this->assertSame(1, Counters::products());
    }

    public function test_the_api_and_assistant_are_refused_a_product(): void
    {
        $this->onServer([], ['products' => 1]);
        $this->product('A');

        $this->expectException(ValidationException::class);
        app(ProductWrites::class)->store(['name' => 'Second', 'sku' => 'B']);
    }

    public function test_the_creator_itself_refuses_a_product(): void
    {
        $this->onServer([], ['products' => 1]);
        $this->product('A');

        $this->expectException(PlanLimitReached::class);
        app(ProductCreator::class)->create(['name' => 'Second', 'sku' => 'B', 'price' => 1]);
    }

    public function test_an_import_is_refused_before_the_store_is_asked(): void
    {
        $this->onServer(['shopee'], ['products' => 1]);
        $this->product('A');

        $client = \Mockery::mock(ShopeeClient::class);
        $client->shouldNotReceive('get', 'post', 'call');

        $result = (new ShopeeItemImport($client))->import([], 123);

        $this->assertSame(['ok' => false, 'message' => 'Not imported. Your plan allows 1 product.'], $result);
    }

    public function test_everything_that_makes_a_product_asks_the_quota(): void
    {
        $makers = $this->filesMatching("/'product'\\)\\s*->insertGetId\\(|new Product\\(\\)/");

        foreach ($makers as $path => $code) {
            $this->assertStringContainsString('Quota::', $code, $path . ' makes products without asking the plan');
        }
        $this->assertArrayHasKey(app_path('Services/Catalog/ProductCreator.php'), $makers, 'the search no longer finds the core product maker');
        foreach (['lazada', 'opencart', 'pedallion', 'shopee', 'shopify', 'tiktok', 'ventacart', 'woocommerce'] as $channel) {
            if (! is_dir(base_path('extensions/' . $channel))) {
                continue;
            }
            $found = array_filter(array_keys($makers), fn ($path) => str_starts_with($path, base_path('extensions/' . $channel . '/')));
            $this->assertNotEmpty($found, 'the search no longer finds the product maker in ' . $channel);
        }
    }

    public function test_a_full_plan_refuses_another_user(): void
    {
        $admin = $this->admin();
        User::factory()->count(2)->create(['user_group_id' => $admin->user_group_id]);
        $this->onServer([], ['users' => 3]);

        $this->actingAs($admin)->get(route('users.index'))->assertSee('3 of 3 users on your plan');
        $this->actingAs($admin)->get(route('users.create'))->assertRedirect(route('users.index'));
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Fourth', 'username' => 'fourth', 'email' => 'fourth@example.com',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
            'user_group_id' => $admin->user_group_id,
        ])->assertSessionHas('error', 'Your plan allows 3 users.');

        $this->assertSame(3, User::count());
    }

    public function test_a_plan_with_room_adds_the_user(): void
    {
        $admin = $this->admin();
        $this->onServer([], ['users' => 10]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Second', 'username' => 'second', 'email' => 'second@example.com',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
            'user_group_id' => $admin->user_group_id,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(2, User::count());
    }

    public function test_a_store_past_the_limit_is_refused_however_it_is_made(): void
    {
        $this->onServer(['shopee'], ['stores' => 0]);

        $this->expectException(PlanLimitReached::class);
        $this->expectExceptionMessage('Your plan allows 0 stores.');
        ShopeeSetting::create(['store_name' => 'Second shop']);
    }

    public function test_every_channel_store_model_counts_as_a_store(): void
    {
        $models = glob(base_path('extensions/*/Models/*Setting.php'));

        foreach ($models as $file) {
            $this->assertStringContainsString('CountsAsStore', (string) file_get_contents($file), $file . ' is a store model the plan does not count');
        }
        $this->assertCount(count($models), Counters::storeModels());
        $this->assertContains(CountsAsStore::class, class_uses_recursive(ShopeeSetting::class));
    }

    public function test_an_api_app_past_the_limit_is_refused(): void
    {
        $this->onServer([], ['api_apps' => 0]);

        $this->expectException(PlanLimitReached::class);
        ApiClient::create(['name' => 'Second app']);
    }

    public function test_a_month_of_orders_past_the_limit_is_refused(): void
    {
        $admin = $this->admin();
        $this->onServer([], ['orders_month' => 0]);

        $this->actingAs($admin)->get(route('orders.index'))->assertSee("This month's are used up", false);

        $this->expectException(PlanLimitReached::class);
        $this->expectExceptionMessage('Your plan allows 0 orders a month.');
        (new Order())->save();
    }

    public function test_every_core_order_insert_asks_the_quota(): void
    {
        foreach ($this->filesMatching("/'order'\\)\\s*->insertGetId\\(/") as $path => $code) {
            $this->assertStringContainsString('Quota::', $code, $path . ' makes orders without asking the plan');
        }
    }

    public function test_a_refusal_comes_back_as_a_message_not_an_error_page(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->post('/_plantest', fn () => throw new PlanLimitReached('Your plan allows 2 stores.'));

        $this->from('/somewhere')->post('/_plantest')
            ->assertRedirect('/somewhere')
            ->assertSessionHas('error', 'Your plan allows 2 stores.');
        $this->postJson('/_plantest')->assertStatus(422)->assertJson(['message' => 'Your plan allows 2 stores.']);
    }
}
