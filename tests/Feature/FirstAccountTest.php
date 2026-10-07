<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FirstAccountTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $override = []): array
    {
        return array_merge([
            'company_name' => 'Reyes Music Supply',
            'name' => 'Maria Santos',
            'username' => 'maria',
            'email' => 'maria@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ], $override);
    }

    public function test_the_seed_makes_no_user(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_an_install_with_no_user_opens_on_the_first_account_page(): void
    {
        $this->get('/login')->assertRedirect(route('first-account'));
        $this->get(route('first-account'))->assertOk()->assertSee('Create your account');
    }

    public function test_the_first_account_is_the_administrator_and_is_signed_in(): void
    {
        $this->post(route('first-account.store'), $this->form())->assertRedirect('/dashboard');

        $user = User::sole();
        $this->assertSame('maria', $user->username);
        $this->assertSame(
            DB::table('user_groups')->where('name', 'Administrator')->value('id'),
            $user->user_group_id
        );
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Reyes Music Supply', Setting::singleton()->company_name);
    }

    public function test_the_business_name_may_be_left_empty(): void
    {
        $before = Setting::singleton()->company_name;

        $this->post(route('first-account.store'), $this->form(['company_name' => '']))->assertRedirect('/dashboard');

        $this->assertSame($before, Setting::singleton()->fresh()->company_name);
    }

    public function test_once_an_account_exists_the_page_is_gone(): void
    {
        User::factory()->create();

        $this->get(route('first-account'))->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_a_second_first_account_is_refused(): void
    {
        $this->post(route('first-account.store'), $this->form())->assertRedirect('/dashboard');
        auth()->logout();

        $this->post(route('first-account.store'), $this->form(['username' => 'intruder', 'email' => 'x@example.com']))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_a_mistyped_confirmation_makes_no_account(): void
    {
        $this->from(route('first-account'))
            ->post(route('first-account.store'), $this->form(['password_confirmation' => 'something-else']))
            ->assertRedirect(route('first-account'))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_the_old_sign_up_route_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
