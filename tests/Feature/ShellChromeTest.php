<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellChromeTest extends TestCase
{
    use RefreshDatabase;

    private const SHELL_PAGE = '/ui-kit-v2';

    public function test_logout_form_is_post_with_csrf_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(self::SHELL_PAGE);

        $response->assertOk();
        $response->assertSee(
            '<form method="POST" action="'.route('logout').'">',
            false
        );
        $response->assertSee('name="_token"', false);
    }

    public function test_get_to_logout_route_is_rejected(): void
    {
        $response = $this->get(route('logout'));

        $response->assertStatus(405);
    }

    public function test_username_is_escaped_in_the_account_menu(): void
    {
        $payload = 'Review <script>x</script> Tester';
        $user = User::factory()->create(['name' => $payload]);

        $response = $this->actingAs($user)->get(self::SHELL_PAGE);

        $response->assertOk();
        $response->assertSee(e($payload), false);
        $response->assertDontSee($payload, false);
    }

    public function test_account_menu_and_search_trigger_render_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(self::SHELL_PAGE);

        $response->assertOk();
        $response->assertSee('x-account__avatar', false);
        $response->assertSee('x-search-trigger', false);
    }

    public function test_guest_is_redirected_instead_of_seeing_shell_chrome(): void
    {
        $response = $this->get(self::SHELL_PAGE);

        $response->assertRedirect(route('login'));
    }
}
