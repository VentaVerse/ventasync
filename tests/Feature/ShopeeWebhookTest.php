<?php

namespace Tests\Feature;

use App\Models\ChannelWebhookEvent;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function push(string $key, ?string $signedUrl = null): \Illuminate\Testing\TestResponse
    {
        $url = route('channels.webhooks.receive', ['channel' => 'shopee']);
        $body = json_encode(['shop_id' => 0, 'code' => 0, 'data' => [], 'timestamp' => 1759478400]);
        $signature = hash_hmac('sha256', ($signedUrl ?? $url) . '|' . $body, $key);

        return $this->call('POST', $url, [], [], [], [
            'HTTP_AUTHORIZATION' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_a_push_signed_with_the_push_partner_key_is_accepted(): void
    {
        ShopeeSetting::create([
            'mode' => 'live',
            'store_name' => 'Main store',
            'partner_key' => encrypt('api-key'),
            'push_partner_key' => encrypt('push-key'),
        ]);

        $this->push('push-key')->assertOk();
        $this->push('someone-elses-key')->assertStatus(401);

        $this->assertSame([true, false], ChannelWebhookEvent::query()->orderBy('id')->pluck('signature_ok')->map(fn ($ok) => (bool) $ok)->all());
    }

    public function test_the_sandbox_push_key_is_accepted_whatever_the_stores_mode(): void
    {
        ShopeeSetting::create([
            'mode' => 'live',
            'store_name' => 'Main store',
            'sandbox_push_partner_key' => encrypt('sandbox-push-key'),
        ]);

        $this->push('sandbox-push-key')->assertOk();
    }

    public function test_a_push_signed_for_the_https_url_is_accepted_when_it_arrives_as_http(): void
    {
        ShopeeSetting::create([
            'mode' => 'live',
            'store_name' => 'Main store',
            'push_partner_key' => encrypt('push-key'),
        ]);

        $https = preg_replace('/^http:/i', 'https:', route('channels.webhooks.receive', ['channel' => 'shopee']));

        $this->push('push-key', $https)->assertOk();
    }

    public function test_the_push_keys_are_saved_encrypted_and_a_blank_field_keeps_them(): void
    {
        $this->artisan('permissions:sync-catalogue');
        $group = \App\Models\Admin\UserGroup::create(['name' => 'shopee settings']);
        $group->permissions()->attach(\App\Models\Admin\Permission::whereIn('key', ['view_shopee/settings', 'manage_shopee/settings'])->pluck('id')->all());
        $user = \App\Models\User::factory()->create(['user_group_id' => $group->id]);
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $this->actingAs($user)
            ->post(route('ext.shopee.save', ['store' => $store->id]), ['env' => 'live', 'push_partner_key' => ' push-key '])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('ext.shopee.save', ['store' => $store->id]), ['env' => 'sandbox', 'sandbox_push_partner_key' => 'sandbox-push-key'])
            ->assertRedirect();

        $raw = $store->fresh();
        $this->assertNotSame('push-key', $raw->push_partner_key);
        $this->assertSame('push-key', $raw->decrypted()->push_partner_key);
        $this->assertSame('sandbox-push-key', $raw->decrypted()->sandbox_push_partner_key);

        $this->actingAs($user)
            ->post(route('ext.shopee.save', ['store' => $store->id]), ['env' => 'live', 'push_partner_key' => ''])
            ->assertRedirect();
        $this->assertSame('push-key', $store->fresh()->decrypted()->push_partner_key);

        $this->actingAs($user)
            ->get(route('ext.shopee.settings.show', ['store' => $store->id]))
            ->assertOk()
            ->assertSee('Webhook URL')
            ->assertSee('Push Partner Key')
            ->assertSee('Sandbox Push Partner Key')
            ->assertSee(preg_replace('/^http:/i', 'https:', route('channels.webhooks.receive', ['channel' => 'shopee'])));
    }
}
