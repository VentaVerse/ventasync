<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiktokThumbTest extends TestCase
{
    use RefreshDatabase;

    public int $detailCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
        $test = $this;
        $this->app->instance(TikTokClient::class, new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function getProduct(string $appKey, string $appSecret, string $accessToken, string $productId, ?string $shopCipher = null): array
            {
                $this->test->detailCalls++;

                return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => [
                    'id' => $productId,
                    'main_images' => $productId === 'tt-bare' ? [] : [['uri' => 'tos-x', 'urls' => ['https://p16-oec-va.tiktokcdn.com/' . $productId . '.jpg']]],
                ]]];
            }
        });
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok thumb ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_tiktok/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    public function test_the_thumb_reads_the_detail_once_and_answers_a_url_or_nothing(): void
    {
        $user = $this->manager();
        $store = (int) TikTokSetting::query()->value('id');

        $this->actingAs($user)->getJson(route('ext.tiktok.products.thumb', ['store' => $store, 'ref' => 'tt-1']))
            ->assertOk()->assertJson(['url' => 'https://p16-oec-va.tiktokcdn.com/tt-1.jpg']);
        $this->actingAs($user)->getJson(route('ext.tiktok.products.thumb', ['store' => $store, 'ref' => 'tt-1']))
            ->assertOk()->assertJson(['url' => 'https://p16-oec-va.tiktokcdn.com/tt-1.jpg']);
        $this->assertSame(1, $this->detailCalls, 'the second read comes from the cache');

        $this->actingAs($user)->getJson(route('ext.tiktok.products.thumb', ['store' => $store, 'ref' => 'tt-bare']))
            ->assertOk()->assertJson(['url' => '']);
        $this->actingAs($user)->getJson(route('ext.tiktok.products.thumb', ['store' => $store, 'ref' => 'no spaces allowed']))
            ->assertOk()->assertJson(['url' => '']);
        $this->assertSame(2, $this->detailCalls, 'a ref that is not an id never reaches TikTok');
    }

    public function test_the_import_row_asks_lazily_and_the_loader_exists(): void
    {
        $view = (string) file_get_contents(base_path('extensions/tiktok/views/products/import.blade.php'));
        $this->assertStringContainsString("data-thumb-url=\"{{ route('ext.tiktok.products.thumb'", $view);
        $js = (string) file_get_contents(base_path('resources/js/pages/channel-import.js'));
        $this->assertStringContainsString('function initLazyThumbs', $js);
        $this->assertStringContainsString('IntersectionObserver', $js);
    }
}
