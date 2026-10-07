<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialRevealTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sandbox-secret-value-32-chars-xx';
    private const TOKEN = 'sandbox-access-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        LazadaSetting::create([
            'mode' => 'sandbox',
            'region' => 'ph',
            'app_key' => '100123',
            'app_secret' => encrypt('live-app-secret'),
            'sandbox_app_key' => '114192',
            'sandbox_app_secret' => encrypt(self::SECRET),
            'sandbox_access_token' => encrypt(self::TOKEN),
            'api_log_mode' => 'all',
        ]);
    }

    private function tierUser(string $label, array $permissions): User
    {
        $group = UserGroup::create(['name' => $label.' '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function reveal(User $user, array $body)
    {
        return $this->actingAs($user)
            ->postJson(route('channels.credential.reveal', ['channel' => 'lazada']), $body);
    }

    public function test_the_manage_tier_can_read_a_stored_credential(): void
    {
        $response = $this->reveal(
            $this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']),
            ['field' => 'sandbox_access_token']
        );

        $response->assertOk();
        $this->assertSame(self::TOKEN, $response->json('value'));
    }

    public function test_the_read_tier_cannot_read_a_stored_credential(): void
    {
        $response = $this->reveal(
            $this->tierUser('Lazada Viewer', ['view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/dashboard']),
            ['field' => 'sandbox_access_token']
        );

        $response->assertForbidden();
        $this->assertNull($response->json('value'));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_a_signed_out_visitor_cannot_read_a_stored_credential(): void
    {
        $response = $this->postJson(
            route('channels.credential.reveal', ['channel' => 'lazada']),
            ['field' => 'sandbox_access_token']
        );

        $this->assertContains($response->status(), [401, 403, 419, 302]);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_a_field_outside_the_allowlist_is_refused(): void
    {
        $response = $this->reveal(
            $this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']),
            ['field' => 'app_key']
        );

        $response->assertForbidden();
        $this->assertNull($response->json('value'));
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $response = $this->actingAs($this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']))
            ->postJson(
                route('channels.credential.reveal', ['channel' => 'not-a-channel']),
                ['field' => 'sandbox_access_token']
            );

        $response->assertForbidden();
    }

    public function test_every_refusal_answers_the_same_way(): void
    {
        $manager = $this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']);
        $viewer = $this->tierUser('Lazada Viewer', ['view_lazada/settings', 'view_lazada/product', 'view_lazada/brand', 'view_lazada/category', 'view_lazada/category_attribute', 'view_lazada/product_group', 'view_lazada/dashboard']);

        $bodies = [
            $this->reveal($viewer, ['field' => 'sandbox_access_token'])->getContent(),
            $this->reveal($manager, ['field' => 'app_key'])->getContent(),
            $this->actingAs($manager)->postJson(
                route('channels.credential.reveal', ['channel' => 'not-a-channel']),
                ['field' => 'sandbox_access_token']
            )->getContent(),
        ];

        $this->assertCount(1, array_unique($bodies),
            'Refusals differ, so a caller can tell which of the three reasons applied.');
    }

    public function test_a_read_is_written_to_the_activity_log_without_the_value(): void
    {
        $this->reveal(
            $this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']),
            ['field' => 'sandbox_app_secret']
        )->assertOk();

        $entry = ActivityLog::query()->where('action', 'credential.revealed')->latest('id')->first();

        $this->assertNotNull($entry, 'Reading a credential was not recorded.');
        $this->assertStringContainsString('sandbox_app_secret', json_encode($entry->getAttributes()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($entry->getAttributes()),
            'The audit trail copied the secret into a second table.');
    }

    public function test_the_settings_page_still_carries_no_credential(): void
    {
        $html = $this->actingAs($this->tierUser('Lazada Manager', ['manage_lazada/settings', 'manage_lazada/product', 'manage_lazada/brand', 'manage_lazada/category', 'manage_lazada/category_attribute', 'manage_lazada/product_group', 'manage_lazada/dashboard']))
            ->get(route('ext.lazada.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);

        $this->assertStringContainsString('stored characters', $html);
    }
}
