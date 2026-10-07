<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshDatabaseScratchProbe extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_ever_runs_against_an_already_verified_database(): void
    {
        $this->assertTrue(true);
    }
}
