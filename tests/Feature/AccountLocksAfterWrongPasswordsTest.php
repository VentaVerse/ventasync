<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use App\Support\Auth\AccountLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountLocksAfterWrongPasswordsTest extends TestCase
{
    use RefreshDatabase;

    private function person(): User
    {
        return User::factory()->create(['username' => 'mara', 'password' => Hash::make('right-secret')]);
    }

    private function webTry(string $password, int $n = 1)
    {
        $response = null;
        for ($i = 0; $i < $n; $i++) {
            $this->app['auth']->forgetGuards();
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.' . (10 + $i)])
                ->post('/login', ['username' => 'mara', 'password' => $password]);
        }

        return $response;
    }

    public function test_the_fifth_wrong_password_locks_the_account_and_even_the_right_one_is_refused(): void
    {
        $user = $this->person();

        $this->webTry('wrong', 4);
        $this->assertFalse(AccountLock::isLocked($user->fresh()), 'four wrong tries are not yet a lock');

        $this->webTry('wrong')->assertSessionHasErrors(['username' => AccountLock::message($user->fresh())]);
        $this->assertTrue(AccountLock::isLocked($user->fresh()));

        $this->webTry('right-secret')->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertTrue(DB::table('activity_logs')->where('action', 'account_locked')->where('subject_id', $user->id)->exists());
    }

    public function test_after_fifteen_minutes_the_right_password_works_again(): void
    {
        $user = $this->person();
        $this->webTry('wrong', 5);

        $this->travel(16)->minutes();
        $this->webTry('right-secret')->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(0, (int) $user->fresh()->failed_logins);
    }

    public function test_a_successful_sign_in_resets_the_count(): void
    {
        $user = $this->person();
        $this->webTry('wrong', 4);
        $this->webTry('right-secret')->assertRedirect();
        $this->post('/logout');

        $this->webTry('wrong', 4);

        $this->assertFalse(AccountLock::isLocked($user->fresh()));
    }

    public function test_the_mobile_app_counts_toward_the_same_lock(): void
    {
        $user = $this->person();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/app/v1/login', ['username' => 'mara', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->assertTrue(AccountLock::isLocked($user->fresh()));
        $this->postJson('/api/app/v1/login', ['username' => 'mara', 'password' => 'right-secret'])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', AccountLock::message($user->fresh()));
    }

    public function test_an_admin_unlocks_it_and_a_viewer_cannot(): void
    {
        $user = $this->person();
        $this->webTry('wrong', 5);

        $viewerGroup = UserGroup::create(['name' => 'Viewers']);
        $viewerGroup->permissions()->attach(Permission::firstOrCreate(['key' => 'view_settings/user'])->id);
        $viewer = User::factory()->create(['user_group_id' => $viewerGroup->id]);
        $this->actingAs($viewer)->post(route('users.unlock', $user->id));
        $this->assertTrue(AccountLock::isLocked($user->fresh()), 'a viewer cannot unlock');

        $adminGroup = UserGroup::create(['name' => 'Admins']);
        $adminGroup->permissions()->attach(Permission::whereIn('key', ['view_settings/user', 'manage_settings/user'])->pluck('id')->all()
            ?: [Permission::firstOrCreate(['key' => 'manage_settings/user'])->id, Permission::firstOrCreate(['key' => 'view_settings/user'])->id]);
        $admin = User::factory()->create(['user_group_id' => $adminGroup->id]);

        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('Locked until')->assertSee(route('users.unlock', $user->id), false);
        $this->actingAs($admin)->post(route('users.unlock', $user->id))->assertRedirect(route('users.index'));

        $this->assertFalse(AccountLock::isLocked($user->fresh()));
        $this->assertTrue(DB::table('activity_logs')->where('action', 'account_unlocked')->where('subject_id', $user->id)->exists());
    }

    public function test_a_new_password_lifts_the_lock(): void
    {
        $user = $this->person();
        $this->webTry('wrong', 5);

        $user->fresh()->update(['password' => Hash::make('brand-new-secret')]);

        $this->assertFalse(AccountLock::isLocked($user->fresh()));
    }
}
