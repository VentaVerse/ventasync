<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_entry_point_hands_off_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
