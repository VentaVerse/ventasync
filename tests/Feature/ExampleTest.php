<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The entry point sends you somewhere; it never renders.
     *
     * This began as Laravel's scaffold test asserting that / returns 200,
     * which is true of a fresh install's welcome page and false of this
     * application: root goes to HomeController@index, which redirects to the
     * dashboard when you are signed in and to the login screen when you are
     * not. A 200 here would mean something had broken.
     *
     * Rewritten to assert what actually happens, so it guards the entry
     * point instead of failing on it. Root hands off to the dashboard
     * unconditionally; the dashboard's own auth middleware is what turns a
     * guest away, which is asserted separately in FinalRoundTest. Both legs
     * are checked here so neither the hand-off nor the gate can quietly
     * disappear.
     */
    public function test_the_entry_point_hands_off_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));

        // And a guest following that hand-off still does not get in.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
