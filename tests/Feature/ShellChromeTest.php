<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the account chrome commit df37fec8 added to
 * resources/views/layouts/shell.blade.php: the account menu (avatar, name,
 * logout form) and the command palette search trigger in the shell top bar.
 *
 * Before this file existed, none of that markup was under automated test.
 * A reviewer wrote a throwaway escaping check while reviewing df37fec8 and
 * deleted it once satisfied, leaving the shell's only sign-out control
 * covered by a single manual browser pass.
 */
class ShellChromeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Four routes render layouts.shell (/sales/orders, /settings,
     * /ui-kit-v2, /integrations). /ui-kit-v2 is a plain reference page with
     * no controller and no query beyond the shell chrome itself, so it is
     * the cheapest one to render for assertions about the shell, not the
     * page content.
     */
    private const SHELL_PAGE = '/ui-kit-v2';

    /**
     * A GET logout link is CSRF-able by any third-party <img> tag; the form
     * must be POST and must carry the token @csrf renders, or that
     * protection does not exist.
     */
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

    /**
     * routes/auth.php registers logout as POST-only. A GET to the same URI
     * must not be a valid way to sign a user out.
     */
    public function test_get_to_logout_route_is_rejected(): void
    {
        $response = $this->get(route('logout'));

        $response->assertStatus(405);
    }

    /**
     * The reviewer's deleted throwaway test, rebuilt: a display name
     * containing markup must come through Blade's {{ }} escaping, not
     * raw. Covers every place shell.blade.php prints the account name
     * (aria-label, title attribute, avatar label, menu panel).
     */
    public function test_username_is_escaped_in_the_account_menu(): void
    {
        $payload = 'Review <script>x</script> Tester';
        $user = User::factory()->create(['name' => $payload]);

        $response = $this->actingAs($user)->get(self::SHELL_PAGE);

        $response->assertOk();
        $response->assertSee(e($payload), false);
        $response->assertDontSee($payload, false);
    }

    /**
     * The account menu is the only sign-out control in the new shell and
     * the search trigger is the only visible affordance for the command
     * palette. A future refactor that silently drops either should fail
     * this test, not wait for the next manual pass.
     */
    public function test_account_menu_and_search_trigger_render_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(self::SHELL_PAGE);

        $response->assertOk();
        $response->assertSee('x-account__avatar', false);
        $response->assertSee('x-search-trigger', false);
    }

    /**
     * A guest hitting a shell page must be bounced to login, never handed
     * a page that tries (and, since auth()->user() is null, would fail)
     * to render an account menu with no user behind it.
     */
    public function test_guest_is_redirected_instead_of_seeing_shell_chrome(): void
    {
        $response = $this->get(self::SHELL_PAGE);

        $response->assertRedirect(route('login'));
    }
}
