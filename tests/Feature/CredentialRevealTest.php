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

/**
 * Reading a stored credential back.
 *
 * Settings forms mask their credentials and never render the real value into
 * the page - commit 19086505, "the stored value never reaches the page source
 * for either tier". That property is load-bearing and the reveal endpoint is
 * the deliberate exception to it, so both halves are pinned here: the page
 * still leaks nothing, and the endpoint hands the value over only to someone
 * holding the channel's manage permission.
 *
 * Every assertion below is one an attacker would have to defeat, which is why
 * they are separate tests rather than one walk through the happy path.
 */
class CredentialRevealTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sandbox-secret-value-32-chars-xx';
    private const TOKEN = 'sandbox-access-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        // The provider must be registered, or the registry cannot resolve
        // 'lazada' to a CredentialRevealer and every reveal refuses for the
        // wrong reason. Same bootstrap the other Lazada suites use.
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
            'api_logging' => true,
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
            $this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']),
            ['field' => 'sandbox_access_token']
        );

        $response->assertOk();
        $this->assertSame(self::TOKEN, $response->json('value'));
    }

    /**
     * The assertion the whole feature turns on. A read tier can open the
     * settings page; it must not be able to read the secrets off it by
     * calling the endpoint the page's own button calls.
     */
    public function test_the_read_tier_cannot_read_a_stored_credential(): void
    {
        $response = $this->reveal(
            $this->tierUser('Lazada Viewer', ['view_lazada', 'view_lazada/dashboard']),
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

    /**
     * The allowlist is the whole defence against a caller naming a column and
     * reading it back. app_key is a real column on the same row, and is
     * deliberately absent from revealableCredentials().
     */
    public function test_a_field_outside_the_allowlist_is_refused(): void
    {
        $response = $this->reveal(
            $this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']),
            ['field' => 'app_key']
        );

        $response->assertForbidden();
        $this->assertNull($response->json('value'));
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $response = $this->actingAs($this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']))
            ->postJson(
                route('channels.credential.reveal', ['channel' => 'not-a-channel']),
                ['field' => 'sandbox_access_token']
            );

        $response->assertForbidden();
    }

    /**
     * Refusals must be indistinguishable. A caller probing for which channels
     * exist, which fields they hold, or which permissions they lack should
     * learn the same nothing from each.
     */
    public function test_every_refusal_answers_the_same_way(): void
    {
        $manager = $this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']);
        $viewer = $this->tierUser('Lazada Viewer', ['view_lazada', 'view_lazada/dashboard']);

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
            $this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']),
            ['field' => 'sandbox_app_secret']
        )->assertOk();

        $entry = ActivityLog::query()->where('action', 'credential.revealed')->latest('id')->first();

        $this->assertNotNull($entry, 'Reading a credential was not recorded.');
        $this->assertStringContainsString('sandbox_app_secret', json_encode($entry->getAttributes()));
        $this->assertStringNotContainsString(self::SECRET, json_encode($entry->getAttributes()),
            'The audit trail copied the secret into a second table.');
    }

    /**
     * The property the reveal endpoint exists to preserve rather than replace:
     * opening the page still hands out nothing.
     */
    public function test_the_settings_page_still_carries_no_credential(): void
    {
        $html = $this->actingAs($this->tierUser('Lazada Manager', ['manage_lazada', 'manage_lazada/dashboard']))
            ->get(route('ext.lazada.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);

        // And it says how much is stored, so the field cannot read as empty.
        $this->assertStringContainsString('stored characters', $html);
    }
}
