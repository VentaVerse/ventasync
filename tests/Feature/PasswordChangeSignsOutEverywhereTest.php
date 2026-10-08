<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordChangeSignsOutEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private function person(): User
    {
        return User::factory()->create(['username' => 'mara', 'password' => Hash::make('old-secret-1')]);
    }

    private function oauthToken(User $user): string
    {
        $clientId = (string) Str::uuid();
        DB::table('oauth_clients')->insert([
            'id' => $clientId, 'name' => 'Claude', 'secret' => null, 'provider' => null,
            'redirect_uris' => '[]', 'grant_types' => '["authorization_code","refresh_token"]', 'revoked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = Str::random(80);
        DB::table('oauth_access_tokens')->insert([
            'id' => $id, 'user_id' => $user->id, 'client_id' => $clientId, 'name' => null, 'scopes' => '[]',
            'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        DB::table('oauth_refresh_tokens')->insert(['id' => Str::random(80), 'access_token_id' => $id, 'revoked' => false, 'expires_at' => now()->addDays(60)]);

        return $id;
    }

    public function test_a_new_password_ends_the_phone_and_assistant_sign_ins(): void
    {
        $user = $this->person();
        $user->createToken('ventasync-mobile');
        $oauth = $this->oauthToken($user);
        $bystander = User::factory()->create();
        $bystander->createToken('ventasync-mobile');

        $user->update(['password' => Hash::make('new-secret-2')]);

        $this->assertSame(0, $user->tokens()->count(), 'the phone is signed out');
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', $oauth)->value('revoked'), 'the assistant sign-in is withdrawn');
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('access_token_id', $oauth)->value('revoked'), 'and cannot renew itself');
        $this->assertSame(1, $bystander->tokens()->count(), 'nobody else is touched');
    }

    public function test_saving_without_a_new_password_signs_nobody_out(): void
    {
        $user = $this->person();
        $user->createToken('ventasync-mobile');

        $user->update(['name' => 'Mara Cruz']);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_another_browser_is_signed_out_when_the_password_changes_elsewhere(): void
    {
        $user = $this->person();
        $this->post('/login', ['username' => 'mara', 'password' => 'old-secret-1'])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();

        $user->fresh()->update(['password' => Hash::make('new-secret-2')]);
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_changing_my_own_password_keeps_me_signed_in_here(): void
    {
        $user = $this->person();
        $user->createToken('ventasync-mobile');
        $this->post('/login', ['username' => 'mara', 'password' => 'old-secret-1'])->assertRedirect();

        $this->put(route('password.update'), [
            'current_password' => 'old-secret-1', 'password' => 'new-secret-2-Long', 'password_confirmation' => 'new-secret-2-Long',
        ])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(0, $user->tokens()->count(), 'my phone still has to sign in again');
    }
}
