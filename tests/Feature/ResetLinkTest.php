<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\ResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetLinkTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $override = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'maria', 'name' => 'Maria Santos', 'password' => Hash::make('forgotten-1'),
            'user_group_id' => DB::table('user_groups')->where('name', 'Administrator')->value('id'),
        ], $override));
    }

    private function path(User $user): string
    {
        $link = ResetLink::for($user);

        return parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);
    }

    private function form(array $override = []): array
    {
        return array_merge(['username' => 'maria', 'password' => 'brand-new-2', 'password_confirmation' => 'brand-new-2'], $override);
    }

    public function test_the_command_prints_a_link_on_the_app_address(): void
    {
        config(['app.url' => 'https://erp.example.com']);
        $user = $this->admin();

        $this->artisan('ventasync:reset-link', ['username' => 'maria'])
            ->expectsOutputToContain('https://erp.example.com/reset-login/' . $user->id . '?')
            ->expectsOutputToContain('Works once, for 30 minutes')
            ->assertSuccessful();
    }

    public function test_without_a_username_the_command_lists_the_administrators(): void
    {
        $this->admin();
        User::factory()->create(['username' => 'packer', 'user_group_id' => null]);

        $this->artisan('ventasync:reset-link')
            ->expectsTable(['Username', 'Name', 'Email'], [['maria', 'Maria Santos', User::firstWhere('username', 'maria')->email]])
            ->assertSuccessful();
    }

    public function test_an_unknown_username_fails_and_lists_the_administrators(): void
    {
        $this->admin();

        $this->artisan('ventasync:reset-link', ['username' => 'nobody'])
            ->expectsOutput('No user is named nobody.')
            ->expectsOutput('Administrators:')
            ->assertFailed();
    }

    public function test_the_link_sets_a_new_username_and_password(): void
    {
        $user = $this->admin(['failed_logins' => 3, 'locked_until' => now()->addMinutes(10)]);
        $path = $this->path($user);

        $this->get($path)->assertOk()->assertSee('Reset your sign-in')->assertSee('value="maria"', false);
        $this->post($path, $this->form(['username' => 'maria.santos']))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Password changed.');

        $user->refresh();
        $this->assertSame('maria.santos', $user->username);
        $this->assertTrue(Hash::check('brand-new-2', $user->password));
        $this->assertNull($user->locked_until, 'a locked account is unlocked');
        $this->assertGuest();
        $this->post('/login', ['username' => 'maria.santos', 'password' => 'brand-new-2'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_link_works_once(): void
    {
        $user = $this->admin();
        $path = $this->path($user);
        $this->post($path, $this->form())->assertRedirect(route('login'));

        $this->get($path)->assertForbidden()->assertSee('This link has expired or was already used.');
        $this->post($path, $this->form(['password' => 'third-try-3', 'password_confirmation' => 'third-try-3']))->assertForbidden();
        $this->assertTrue(Hash::check('brand-new-2', $user->fresh()->password));
    }

    public function test_the_link_expires_after_30_minutes(): void
    {
        $user = $this->admin();
        $path = $this->path($user);

        $this->travel(31)->minutes();

        $this->get($path)->assertForbidden();
        $this->post($path, $this->form())->assertForbidden();
        $this->assertTrue(Hash::check('forgotten-1', $user->fresh()->password));
    }

    public function test_a_changed_link_is_refused(): void
    {
        $user = $this->admin();
        $other = $this->admin(['username' => 'jun', 'name' => 'Jun Cruz']);
        $tampered = str_replace('/reset-login/' . $user->id . '?', '/reset-login/' . $other->id . '?', $this->path($user));

        $this->get($tampered)->assertForbidden();
        $this->get('/reset-login/' . $user->id)->assertForbidden();
        $this->post($tampered, $this->form(['username' => 'jun']))->assertForbidden();
        $this->assertTrue(Hash::check('forgotten-1', $other->fresh()->password));
    }

    public function test_the_username_must_be_free_and_the_password_confirmed(): void
    {
        $user = $this->admin();
        $this->admin(['username' => 'jun']);
        $path = $this->path($user);

        $this->from($path)->post($path, $this->form(['username' => 'jun']))->assertSessionHasErrors('username');
        $this->from($path)->post($path, $this->form(['password_confirmation' => 'different-9']))->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('forgotten-1', $user->fresh()->password), 'a refused form changes nothing, so the link still works');
        $this->get($path)->assertOk();
    }

    public function test_the_reset_signs_the_user_out_of_the_phone_app(): void
    {
        $user = $this->admin();
        $user->createToken('ventasync-mobile');

        $this->post($this->path($user), $this->form())->assertRedirect(route('login'));

        $this->assertSame(0, $user->tokens()->count());
    }
}
