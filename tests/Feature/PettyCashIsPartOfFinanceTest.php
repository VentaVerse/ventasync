<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PettyCashIsPartOfFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_petty_cash_addresses_redirect_to_finance(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/petty-cash')->assertRedirect('/finance/petty-cash')->assertStatus(301);
        $this->get('/petty-cash/settings?tab=roles')->assertRedirect('/finance/petty-cash/settings?tab=roles');
    }
}
